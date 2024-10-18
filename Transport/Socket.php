<?php
namespace Tanbolt\Curl\Transport;

use Throwable;
use Tanbolt\Curl\Stream;
use Tanbolt\Curl\Helper;
use Tanbolt\Curl\Request;
use Tanbolt\Curl\Response;
use Tanbolt\Curl\StreamInterface;
use Tanbolt\Curl\TransportInterface;
use Tanbolt\Curl\Exception\RequestException;
use Tanbolt\Curl\Exception\TransferException;

class Socket implements TransportInterface
{
    /**
     * 创建 socket 失败
     */
    const CONNECT_FAILED = 7;

    /**
     * chunked body 每段最大长度
     */
    const CHUNK_MAX_SIZE = 2 << 31;

    /**
     * @var array
     */
    private static $nobodyMethods = ['TRACE', 'HEAD', 'GET'];

    /**
     * @var int
     */
    private $maxExecuteTime;

    /**
     * @var array
     */
    private $sockets = [];

    /**
     * @var bool
     */
    private $closing = false;

    /**
     * Socket constructor.
     */
    public function __construct()
    {
        $this->maxExecuteTime = (int) ini_get('max_execution_time');
        set_time_limit(0);
    }

    /**
     * Socket destruct.
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * @inheritdoc
     * @throws Throwable
     */
    public function fetch(Request $request)
    {
        $this->fetchMulti([$request]);
        return $request->response;
    }

    /**
     * @inheritdoc
     * @throws Throwable
     */
    public function fetchMulti(array $requests, int $maxConnection = 0)
    {
        set_time_limit(0);
        $count = 0;
        $success = 0;
        $collection = [];
        $readSockets = [];
        $writeSockets = [];
        // 根据最大连接数 创建并添加第一批 sockets
        $maxConnection = $maxConnection ?: Helper::MAX_OPEN_SOCKET;
        while ($request = array_shift($requests)) {
            if ($socket = $this->getSocket($request)) {
                $fd = (int) $socket;
                $collection[$fd] = $request;
                $writeSockets[$fd] = $socket;
                if (++$count >= $maxConnection) {
                    break;
                }
            }
        }
        // 开始循环读写空闲 sockets, 在 socket 完成请求前
        // 同一个 socket 在同一时间只会且必须在 $writeSockets 或 $readSockets 的其中一个
        while (count($writeSockets) || count($readSockets)) {
            $e = null;
            $read = $readSockets;
            $write = $writeSockets;
            $ret = stream_select($read, $write, $e, 0, 200000);
            if (!$ret) {
                continue;
            }
            // 写入 socket 请求
            if ($write) {
                foreach ($write as $socket) {
                    $fd = (int) $socket;
                    $request = $collection[$fd] ?? null;
                    if (!$request) {
                        // 不存在 request? 这种请情况理论上不应发生, 若真有, 结束 socket
                        @fclose($socket);
                        unset($writeSockets[$fd]);
                        continue;
                    }
                    try {
                        // 写入结束后, 不再监测 socket 写入状态, 开始监测响应状态
                        if (!static::writeSocket($request, $socket)) {
                            unset($writeSockets[$fd]);
                            $readSockets[$fd] = $socket;
                        }
                    } catch (Throwable $e) {
                        // 发生错误, 结束 socket
                        unset($writeSockets[$fd]);
                        static::closeSocket($request, $socket, $e);
                    }
                }
            }
            // 读取 sockets 响应
            $added = 0;
            if ($read) {
                foreach ($read as $socket) {
                    $fd = (int) $socket;
                    $request = $collection[$fd] ?? null;
                    if (!$request) {
                        // 不存在 request? 这种请情况理论上不应发生, 若真有, 结束 socket
                        @fclose($socket);
                        unset($readSockets[$fd]);
                        continue;
                    }
                    try {
                        $next = static::readSocket($request, $socket);
                        // 继续等待 socket 响应
                        if (true === $next) {
                            continue;
                        }
                        // 响应接收完毕, 不再监测响应状态
                        unset($readSockets[$fd]);
                        // 处理完毕, 结束 socket
                        if (!$next) {
                            $added++;
                            static::reuseSocket($request, $socket);
                            static::closeRequest($request);
                            continue;
                        }
                        // 在回调函数中发起了新请求
                        if (is_string($next)) {
                            $next = $this->reuseSocket($request, $socket)->getSocket($request, true);
                        }
                        // 返回 socket, 重新监测 socket 写入状态
                        if (is_resource($next)) {
                            $fd = (int) $next;
                            $collection[$fd] = $request;
                            $writeSockets[$fd] = $next;
                        } else {
                            throw new TransferException('Lost request connection socket');
                        }
                    } catch (Throwable $e) {
                        // 发生错误, 结束 socket
                        unset($readSockets[$fd]);
                        static::closeSocket($request, $socket, $e);
                    }
                }
            }
            $success += $added;
            // 有 socket 结束, 添加仍在队列中的 request
            while ($added && $request = array_shift($requests)) {
                if ($socket = $this->getSocket($request)) {
                    $fd = (int) $socket;
                    $collection[$fd] = $request;
                    $writeSockets[$fd] = $socket;
                    $added--;
                }
            }
        }
        //不关闭已缓存 socket, 再次使用对象请求可重用连接
        //$this->close();
        return $success;
    }

    /**
     * 创建请求 $request 的 socket
     * @param Request $request
     * @param false $throw
     * @return false|resource
     * @throws Throwable
     */
    private function getSocket(Request $request, bool $throw = false)
    {
        if (!static::preparedRequest($request, $throw)) {
            return false;
        }
        $hash = $request->__extend['hash'] ?? null;
        if ($hash && isset($this->sockets[$hash])) {
            $socket = array_shift($this->sockets[$hash]);
            if (!count($this->sockets[$hash])) {
                unset($this->sockets[$hash]);
            }
            if (static::isSocketAlive($socket)) {
                // 若是复用代理通道的 socket, 应标记通道已创建
                if ($request->__extend['proxy']) {
                    $request->__extend['request_tunnel'] = true;
                }
                static::debug(
                    $request, '* Re-using existing connection! (#'.((int) $socket).') with host '.$request->host
                );
                return $socket;
            }
        }
        return static::createSocket($request, $throw);
    }

    /**
     * 先不关闭 sockets, 缓存起来以便复用
     * @param Request $request
     * @param $socket
     * @return $this
     */
    private function reuseSocket(Request $request, $socket)
    {
        $hash = $request->__extend['hash'] ?? null;
        if (!$hash) {
            static::closeSocket($request, $socket);
            return $this;
        }
        if (!isset($this->sockets[$hash])) {
            $this->sockets[$hash] = [];
        }
        $this->sockets[$hash][] = $socket;
        return $this;
    }

    /**
     * close all request
     * @return $this
     */
    public function close()
    {
        if ($this->closing) {
            return $this;
        }
        set_error_handler(function(){});
        $this->closing = true;
        foreach ($this->sockets as $sockets) {
            foreach ($sockets as $socket) {
                fclose($socket);
            }
        }
        $this->sockets = [];
        if ($this->maxExecuteTime) {
            set_time_limit($this->maxExecuteTime);
        }
        restore_error_handler();
        $this->closing = false;
        return $this;
    }

    /**
     * 由 request 准备请求配置
     * @param Request $request
     * @param bool $throw
     * @return bool
     * @throws Throwable
     */
    private static function preparedRequest(Request $request, bool $throw = false)
    {
        try {
            if (!$request->response) {
                $request->setResponse(new Response());
            }
            // 若 request saveTo 参数发生变动, 强制重建 body stream
            $prevSaveTo = $request->__extend[':saveTo'] ?? null;
            $request->__extend = static::preparedRequestOptions($request);
            if (!$request->__extend['nobody']) {
                $force = false;
                if ($prevSaveTo !== $request->saveTo) {
                    $force = true;
                    $request->__extend[':saveTo'] = $request->saveTo;
                }
                Helper::setResponseBody($request, $force);
            }
            static::resetRequestInfo($request);
            return true;
        } catch (Throwable $e) {
            if ($throw) {
                throw $e;
            }
            static::closeRequest($request, $e);
            return false;
        }
    }

    /**
     * 根据 request 设置准备请求配置
     * @param Request $request
     * @return array
     */
    private static function preparedRequestOptions(Request $request)
    {
        if (empty($request->host)) {
            throw new RequestException('Request url must be set.');
        }
        $options = [
            'launch' => microtime(true)
        ];

        // version
        if ($request->version == 1) {
            $options['version'] = '1.0';
        } elseif ($request->version == 2) {
            $options['version'] = '2';
        } else {
            $options['version'] = '1.1';
        }

        // urls
        $url = $request->url;
        $query = $request->urlQuery;
        if (!empty($request->queries)) {
            $add = (empty($query) ? '' : '&') . http_build_query(
                $request->queries, null, '&', PHP_QUERY_RFC3986
            );
            $url .= (empty($query) ? '?' : '') . $add;
            $query .= $add;
        }
        $options['urls'] = $urls = (object) [
            'secure' => 'https' === $request->scheme,
            'url' => $url,
            'scheme' => $request->scheme,
            'host' => $request->host,
            'port' => $request->port,
            'path' => $request->urlPath,
            'query' => $query
        ];

        // header
        $header = $request->headers;

        // header: Accept
        if (!isset($header['Accept'])) {
            $header['Accept'] = '*/*';
        }

        // header: Accept-Encoding
        if (!isset($header['Accept-Encoding']) && $request->useEncoding) {
            $options['encoding'] = true;
            $header['Accept-Encoding'] = 'deflate, gzip';
        }

        // header: Authorization
        if (!isset($header['Authorization'])) {
            if ($request->auth) {
                list($user, $pass, $type) = array_pad($request->auth, 3, null);
            } else {
                $user = $request->user;
                $pass = $request->pass;
                $type = null;
            }
            if ($user) {
                if (Request::AUTH_BASIC === $type) {
                    $header['Authorization'] = Helper::getBasicAuth($user, $pass);
                } elseif ($type && Request::AUTH_ANY !== $type && Request::AUTH_ANYSAFE !== $type &&
                    (Request::AUTH_GSSNEGOTIATE & $type || Request::AUTH_NTLM & $type)
                ) {
                    // 仅支持 basic 和 digest
                    throw new RequestException('Request auth type only support AUTH_BASIC and AUTH_DIGEST');
                } else {
                    $options['authUser'] = $user;
                    $options['authPass'] = $pass;
                }
            }
        }

        // header:Content-Length, 不能手动设置, 由 body 决定
        unset($header['Content-Length']);
        $options['body'] = $body = in_array($request->method, self::$nobodyMethods)
            ? null : Helper::getRequestBody($request, $header);
        if ($body) {
            $size = $body instanceof StreamInterface ? $body->size() : strlen($body);
            if ($size) {
                $header['Content-Length'] = $size;
            }
        } elseif (in_array($request->method, ['PUT', 'POST'])) {
            $header['Content-Length'] = 0;
        }
        $options['header'] = $header;
        $options['method'] = $request->method ?: ($body ? 'POST' : 'GET');
        $options['nobody'] = 'HEAD' === $request->method;

        // proxy
        $proxy = Helper::getRequestProxy($request);
        if ($proxy) {
            // 仅支持 AUTH_BASIC
            if ($proxy['auth'] && Request::AUTH_BASIC !== $proxy['auth']) {
                throw new RequestException('Proxy auth type only support AUTH_BASIC');
            }
            // https 代理使用方法: 连接代理服务器 -> 握手 -> 发送 Connect 连接目标 -> 与目标握手 -> 传输数据
            // 传输的数据应该是 先使用目标握手得到的key加密 -> 再使用代理服务器key加密, php 的握手操作对于同一个 stream 仅能握手一次
            // 就是说不支持双层 SSL，那么第二次握手就需要手动发送 Client Hello 进行握手, 得到 key 之后手动使用该 key 加密
            // 发送给已经与代理握手的 socket, php 会自动二次加密, 但这个过程就比较复杂了, 考虑到 https 代理服务器较少, 就不再实现了
            if(!in_array($proxy['scheme'], ['http', 'socks5', 'socks4', 'socks4a'])) {
                throw new RequestException('Proxy protocol ['.$proxy['scheme'].'] not support');
            }
        }
        $options['proxy'] = $proxy;

        // 更新 redirects charset
        static::updateRedirects($request, $urls->url, true);
        $request->response->__setResponseValue('charset', $request->charset);

        // cookieJar
        $options['cookieJar'] = isset($request->__extend['cookieJar']) && $request->autoCookie
            ? $request->__extend['cookieJar'] : [];

        // hash
        static::setRequestSocketHash($request, $options);
        return $options;
    }

    /**
     * 当前 socket 连接 request 使用的参数配置，当这些参数配置都未发生变化时，可以重用 socket 继续请求
     * @param Request $request
     * @param array $options
     */
    private static function setRequestSocketHash(Request $request, array &$options = [])
    {
        // 只对 http 协议重用 socket, https 重用有风险（比如服务端修改了 SSL 认证参数，重用旧连接无法重新认证）
        if ('http' !== $request->scheme) {
            return;
        }
        $hash = [];
        $factor = ['tcpDelay', 'forceIp', 'hostResolver', 'scheme', 'host', 'port', 'proxy', 'useSystemProxy'];
        foreach ($factor as $key) {
            $hash[$key] = $request->{$key};
        }
        $options['hash'] = md5(serialize($hash));
    }

    /**
     * 初始化 request info 信息
     * @param Request $request
     * @param null $url
     */
    private static function resetRequestInfo(Request $request, $url = null)
    {
        $request->__extend['start'] = microtime(true);
        $request->__extend['info'] = [
            'url' =>  $url ?: $request->url,
            'content_type' => null,
            'http_code' => 0,

            'request_size' => 0,
            'size_upload' => 0,
            'speed_upload' => 0,

            'header_size' => 0,
            'size_download' => 0,
            'speed_download' => 0,
        ];
    }

    /**
     * 设置 info 键值
     * @param Request $request
     * @param array|string $name
     * @param null $value
     * @param bool $plus
     */
    private static function setRequestInfo(Request $request, $name, $value = null, bool $plus = false)
    {
        if (is_array($name)) {
            foreach ($name as $k => $v) {
                $request->__extend['info'][$k] = $v;
            }
        } elseif ($plus) {
            $request->__extend['info'][$name] += $value;
        } else {
            $request->__extend['info'][$name] = $value;
        }
    }

    /**
     * 设置 耗时类型 的 info
     * @param Request $request
     * @param string $name
     * @param bool $once
     */
    private static function setRequestTimeInfo(Request $request, string $name, bool $once = false)
    {
        if (!$once || !isset($request->__extend['info'][$name])) {
            static::setRequestInfo(
                $request, $name,
                number_format(microtime(true) - $request->__extend['start'], 6)
            );
        }
    }

    /**
     * 创建新的 socket 连接
     * @param Request $request
     * @param bool $throw
     * @return false|resource
     * @throws Throwable
     */
    private static function createSocket(Request $request, bool $throw = false)
    {
        try {
            return static::makeSocket($request);
        } catch (Throwable $e) {
            if ($request->tryTime > 0 && $e instanceof TransferException &&
                self::CONNECT_FAILED === $e->error() && $request->response->tryTime < $request->tryTime
            ) {
                $request->response->__setResponseValue('tryTime', $request->response->tryTime + 1);
                return static::createSocket($request);
            }
            if ($throw) {
                throw $e;
            }
            static::closeRequest($request, $e);
            return false;
        }
    }

    /**
     * 创建 socket 客户端
     * @param Request $request
     * @return resource
     */
    private static function makeSocket(Request $request)
    {
        $options = $request->__extend;
        $urls = $options['urls'];
        $proxy = $options['proxy'];

        // 上下文选项
        $contextSocket = [];
        if (!$request->tcpDelay) {
            $contextSocket['tcp_nodelay'] = true;
            static::debug($request, '* TCP_NODELAY set');
        }
        if (null !== $request->forceIp) {
            $contextSocket['bindto'] = $forceIp = $request->forceIp ? '0:0' : '[0]:0';
            static::debug($request, '* TCP_BINDTO '.$forceIp);
        }
        $context = [];
        if (count($contextSocket)) {
            $context['socket'] = $contextSocket;
        }
        if ($ssl = $urls->secure ? static::getSSLContext($request) : null) {
            $context['ssl'] = $ssl;
        }

        // 要打开的 socket 地址
        if ($proxy) {
            $open = 'tcp://'.$proxy['host'].($proxy['port'] ? ':'.$proxy['port'] : '');
            static::debug($request, '* Connected to proxy server '.$open, false);
        } else {
            $prefix = ($ssl ? 'tls' : 'tcp').'://';
            $open = $open2 = $prefix.$urls->host . ':' . $urls->port;
            if ($resolve = static::getHostResolver($request, $urls->host, $urls->port)) {
                $open = $prefix.$resolve;
                static::debug($request, '* Use custom host resolver');
            }
            static::debug($request, '* Connected to '.$open2, false);
        }

        // 创建 socket
        static::handleError($errorMsg, $errorCode);
        if (!$context = stream_context_create($context)) {
            throw new TransferException('Create stream context resource failed');
        }
        $error = 0;
        $message = null;
        $timeout = (int) $request->timeout;
        $socket = stream_socket_client(
            $open, $error, $message, $timeout, STREAM_CLIENT_CONNECT, $context
        );
        restore_error_handler();

        // 创建失败
        if (!$socket) {
            $error = self::CONNECT_FAILED;
            if (empty($message)) {
                if ($message = static::getSSLError($errorMsg)) {
                    // ssl 异常不重试
                    $error = 0;
                } else {
                    $message = $errorMsg ? static::getUtf8Message($errorMsg) : 'Create socket failed, Unknown error.';
                }
            } else {
                $message = static::getUtf8Message($message);
            }
            static::debug($request, "\r\n", false);
            throw (new TransferException($message))->setError($error);
        }

        // 设置 dns 耗时 / 连接时间 (这里其实是将二者合并了, dns 查询时间也设置成了连接时间)
        static::setRequestTimeInfo($request, 'namelookup_time');
        static::setRequestTimeInfo($request, 'connect_time', true);

        // 设置 ip port info
        $ip = stream_socket_get_name($socket, true);
        list($address, $port) = explode(':', $ip);
        static::setRequestInfo($request, [
            'primary_ip' => $address,
            'primary_port' => $port
        ]);
        static::debug($request, " ($ip)".' (#'.((int) $socket).')');

        // 处理 SSL 证书信息
        static::parseCertInformation($request, $socket);

        // 设置为非阻塞
        if (!stream_set_blocking($socket, 0)) {
            fclose($socket);
            throw (new TransferException('Change stream to non-blocking failed'));
        }
        // 新连接, request 若使用代理, 需重新创建代理通道
        unset($request->__extend['request_tunnel']);
        return $socket;
    }

    /**
     * 获取域名手动指定的 dns ip
     * @param Request $request
     * @param string $host
     * @param string $port
     * @return string|null
     */
    private static function getHostResolver(Request $request, string $host, string $port)
    {
        $start = $host.':'.$port.':';
        foreach ($request->hostResolver as $item) {
            if (Helper::strStartsWith($item, $start)) {
                return substr($item, strlen($start)).':'.$port;
            }
        }
        return null;
    }

    /**
     * 获取 socket ssl 上下文 (暂时未支持 https 代理, 所以这里 proxy=true 未使用)
     * @param Request $request
     * @param bool $proxy
     * @return ?array
     */
    private static function getSSLContext(Request $request, bool $proxy = false)
    {
        $ssl = [];
        // CA 证书校验
        $verify = $proxy ? $request->proxyVerify : $request->sslVerify;
        if ($verify) {
            if (is_string($verify)) {
                if (file_exists($verify)) {
                    $ssl['cafile'] = $verify;
                } elseif (is_dir($verify)) {
                    $ssl['capath'] = $verify;
                } else {
                    throw new RequestException("SSL CA bundle not found: $verify");
                }
            } elseif (PHP_VERSION_ID < 50600) {
                $ssl['cafile'] = Helper::getCaPath();
            }
            $ssl['verify_peer'] = true;
        } else {
            $ssl['verify_peer'] = false;
        }
        $ssl['verify_peer_name'] = $proxy ? $request->proxyVerifyHost : $request->sslVerifyHost;
        // 若使用代理或自定义host, 会导致域名校验失败, 所以这里手动设置 peer_name (即 Host)
        if (!$proxy && $request->sslVerifyHost) {
            $ssl['peer_name'] = $request->__extend['urls']->host;
        }
        $ssl['capture_peer_cert'] = (bool) $request->debug;
        $ssl['capture_peer_cert_chain'] = !$proxy && $request->sslCaptureInfo;

        // SSL 双向认证证书
        $sslCert = $proxy ? $request->proxyCert : $request->sslCert;
        if ($sslCert) {
            list($cert, $key, $pass) = array_pad($sslCert, 3, null);
            if (!$cert || !file_exists($cert)) {
                throw new RequestException("SSL certificate not found: $cert");
            }
            if ($key && !file_exists($key)) {
                throw new RequestException("SSL certificate key not found: $key");
            }
            static::debug($request, '* Use openssl local cert: '.($cert = realpath($cert)));
            $ssl['local_cert'] = $cert;
            $ssl['passphrase'] = $pass ?: '';
            if ($key) {
                static::debug($request, '* Use openssl local cert: '.($key = realpath($key)));
                $ssl['local_pk'] = $key;
            }
        }
        return count($ssl) ? $ssl : null;
    }

    /**
     * 处理 SSL 证书信息
     * @param Request $request
     * @param resource $socket
     */
    private static function parseCertInformation(Request $request, $socket)
    {
        try {
            $ssl = stream_context_get_options($socket);
            $ssl = $ssl['ssl'] ?? null;
            if (!$ssl) {
                return;
            }
            $cert = [];
            $chain = $ssl['peer_certificate_chain'] ?? null;
            if ($chain) {
                foreach ($chain as $source) {
                    // 需注意: 这里返回的 cert 信息与 libcurl 不同
                    $cert[] = openssl_x509_parse($source);
                }
            }
            static::setRequestInfo($request, 'cert', $cert);
            if (!$request->debug) {
                return;
            }
            $info = '';
            $meta = stream_get_meta_data($socket);
            if (isset($meta['crypto'])) {
                $info .= sprintf(
                    '* SSL connection using %s / %s',
                    $meta['crypto']['cipher_version'] ?? $meta['crypto']['protocol'],
                    $meta['crypto']['cipher_name']
                );
            }
            if (isset($ssl['peer_certificate'])) {
                $verify = $ssl['verify_peer'] && $ssl['verify_peer_name'] ? 'SSL certificate verify ok' : (
                    $ssl['verify_peer'] ? 'SSL verify peer ok, not verify peer name'
                        : 'SSL verify peer name ok, not verify peer'
                );
                $certificate = openssl_x509_parse($ssl['peer_certificate']);
                $info .= "\r\n".sprintf(
                    "* Server certificate:\r\n" .
                    "* 	subject: %s\r\n" .
                    "* 	start date: %s\r\n" .
                    "* 	expire date: %s\r\n" .
                    "* 	issuer: %s\r\n" .
                    '*  %s.',
                    static::buildCertValue($certificate['subject']),
                    gmdate('Y-m-d H:i:s \G\M\T', $certificate['validFrom_time_t']),
                    gmdate('Y-m-d H:i:s \G\M\T', $certificate['validTo_time_t']),
                    static::buildCertValue($certificate['issuer']),
                    $verify
                );
            }
            static::debug($request, $info);
        } catch (Throwable $e) {
            // do nothing
        }
    }

    /**
     * 拼接 ssl 证书信息
     * @param array $arr
     * @param string $join
     * @return string
     */
    private static function buildCertValue(array $arr, string $join = ';')
    {
        $str = '';
        foreach ($arr as $key=>$value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $str .= ('' === $str ? '' : $join.' ').$key.'='.$item;
                }
            } else {
                $str .= ('' === $str ? '' : $join.' ').$key.'='.$value;
            }
        }
        return $str;
    }

    /**
     * 获取 ssl 错误信息
     * @param ?string $errorMsg
     * @return string|false
     */
    private static function getSSLError(string $errorMsg = null)
    {
        $errorString = '';
        if (extension_loaded('openssl')) {
            while (($sslError = openssl_error_string()) != false) {
                $errorString .= "; SSL error: $sslError";
            }
        }
        if (!empty($errorString)) {
            return $errorString;
        }
        if (!empty($errorMsg)) {
            if (stripos($errorMsg, 'openssl') !== false || stripos($errorMsg, 'ssl error') !== false) {
                return $errorMsg;
            }
        }
        return false;
    }

    /**
     * 尝试将不是 utf8 编码的字符串转为 utf8
     * @param string $errorMsg
     * @return string
     */
    private static function getUtf8Message(string $errorMsg)
    {
        if (function_exists('mb_detect_encoding')) {
            // 实测发现, 这里的异常消息不一定是 utf-8 编码, 所以尝试转换一下
            $encoding = mb_detect_encoding($errorMsg, 'UTF-8, ASCII, GBK, GB2312');
            if ($encoding && 'UTF-8' !== $encoding) {
                $errorMsg = Helper::convertToUtf8($errorMsg, $encoding);
            }
        }
        return $errorMsg;
    }

    /**
     * 写入 或 读取 socket, 同时检测异常
     * @param Request $request
     * @param $socket
     * @param string|bool $extra
     * @param bool $waitClose
     * @return int|string|null
     */
    private static function syncSocket(Request $request, $socket, $extra = false, bool $waitClose = false)
    {
        // 校验 socket 是否超时
        if ($request->timeout &&  $request->timeout < $duration = microtime(true) - $request->__extend['launch']) {
            throw new TransferException('Operation timed out after '.floor($duration * 1000).' milliseconds');
        }
        static::handleError($errorMsg, $errorCode);
        $isRead = true === $extra || false === $extra;
        if (!$isRead) {
            // 写入数据
            $result = fwrite($socket, $extra);
        } elseif (true === $extra) {
            // 读取一行
            $result = fgets($socket);
        } else {
            // 读取当前所有缓冲
            $result = stream_get_contents($socket);
        }
        restore_error_handler();
        if ($isRead ? strlen($result) : (false !== $result)) {
            $request->__extend['sync_fail'] = 0;
            return $result;
        }
        // 失败: 写入或读取失败有可能是因为 socket 繁忙导致的, 可以等待其处理完毕后继续
        // 但有时候并不是, 比如 ssl 数据解密失败导致的, 这会导致读写操作陷入死循环, 需加以判断
        if (!static::isSocketAlive($socket)) {
            $message = 'Connection was closed by the remote host';
            // 响应有可能是 http1.0, 以断开连接作为响应完毕的标识, 此时不能算为异常
            if ($waitClose) {
                static::debug($request, '* '.$message);
                return true;
            }
            throw new TransferException(($isRead ? 'Read: ' : 'Write: ').$message);
        }
        // 有异常也不代表完全失败, 但若连续出现异常, 中断以避免陷入死循环了
        if (null !== $errorMsg) {
            if (!isset($request->__extend['sync_fail'])) {
                $request->__extend['sync_fail'] = 1;
            } else {
                $request->__extend['sync_fail']++;
            }
            if ($request->__extend['sync_fail'] > 50) {
                throw new TransferException(($isRead ? 'Read' : 'Write').': '.static::getUtf8Message($errorMsg));
            }
        }
        $request->__extend['sync_fail'] = 0;
        return $isRead ? null : 0;
    }

    /**
     * 发送 $request header/body 到 socket
     * @param Request $request
     * @param resource $socket
     * @return bool
     * @throws Throwable
     */
    private static function writeSocket(Request $request, $socket)
    {
        // 先写入代理创建通道
        if (null !== $proxyWrite = static::writeProxy($request, $socket)) {
            return $proxyWrite;
        }
        $options = $request->__extend;

        /** @var string|StreamInterface $body */
        $body = $options['body'];
        $header = $options['header'];
        $isFirst = !isset($options['request_buffer']);
        $isStream = $body && $body instanceof StreamInterface;
        if ($isFirst) {
            // 首次, 先写入 header 数据
            $urls = $options['urls'];
            $requestUrl = $urls->path . ($urls->query ? '?'.$urls->query : '');
            // header cookie
            $cookie = $request->autoCookie
                ? Helper::getRequestCookie($request->__extend['cookieJar'], $urls->host, $urls->path, $urls->secure)
                : '';
            $reqCookie = $header['Cookie'] ?? null;
            if ($reqCookie && is_array($reqCookie)) {
                $reqCookie = join('; ', $reqCookie);
            }
            $cookie .= $cookie ? ($reqCookie ? '; '.$reqCookie : '') : $reqCookie;
            if ($cookie) {
                $header['Cookie'] = $cookie;
            }
            // chunked request body
            $stringBody = '';
            if ($body) {
                if ($isStream) {
                    $body->rewind();
                    if (null === $body->size()) {
                        $header['Transfer-Encoding'] = 'chunked';
                        $request->__extend['request_buffer_chunked'] = true;
                    }
                } else {
                    $stringBody = $body;
                }
            }
            // header
            $send = [];
            $send[] = sprintf('%s %s HTTP/%s', $options['method'], $requestUrl, $options['version']);
            $send[] = 'Host: ' . $urls->host . ($urls->port ? ':'.$urls->port : '');
            foreach ($header as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $v) {
                        if ($v) {
                            $send[] = sprintf('%s: %s', $key, $v);
                        }
                    }
                } elseif ($value) {
                    $send[] = sprintf('%s: %s', $key, $value);
                }
            }
            $buffer = trim(implode("\r\n", $send)). "\r\n\r\n";
            static::debug($request, '> '.$buffer, false);
            $request->__extend['request_start'] = microtime(true);
            static::setRequestInfo($request, 'request_size', strlen($buffer));
            $buffer .= $stringBody;
        } else {
            // 非首次, 写入上次遗留数据
            $buffer = $request->__extend['request_buffer'];
        }
        // 发送本次应写入数据
        $size = strlen($buffer);
        $len = static::syncSocket($request, $socket, $buffer);
        if ($isFirst) {
            static::setRequestTimeInfo($request, 'pretransfer_time');
        }
        // 继续等待
        if (0 === $len) {
            return true;
        }
        static::setRequestInfo($request, 'size_upload', $len, true);
        // 触发 onUpload 回调
        if ($body && $request->onUpload) {
            $totalLen = $header['Content-Length'] ?? 0;
            $uploaded = $request->__extend['info']['size_upload'] -  $request->__extend['info']['request_size'];
            static::triggerCallbackFunction($request, 'onUpload', [$uploaded, $totalLen, $request], false);
        }
        // 仅写入部分, 保留剩余以便下次写入
        if ($len !== $size) {
            $request->__extend['request_buffer'] = substr($buffer, $len);
            return true;
        }
        // 全部写入, 若没有 body 或 string body 或 stream body 读取完毕, 结束发送
        if (!$isStream || $body->eof()) {
            if ($size = ($request->__extend['info']['size_upload'] -= $request->__extend['info']['request_size'])) {
                $start = $request->__extend['request_start'];
                static::debug($request, '* upload completely sent off: '.$size.' bytes');
                static::setRequestInfo(
                    $request, 'speed_upload',
                    floor($size / max(1, microtime(true) - $start))
                );
            }
            // 移除写入操作创建的临时变量
            unset(
                $request->__extend['request_start'],
                $request->__extend['request_buffer'],
                $request->__extend['request_buffer_chunked']
            );
            return false;
        }
        // 分配下次应写入数据
        $next = $body->read(Helper::CHUNK_SIZE);
        // 若是 chunked body, 根据协议格式化数据
        if (isset($request->__extend['request_buffer_chunked'])) {
            $next = dechex(strlen($next))."\r\n".$next."\r\n";
            if ($body->eof()) {
                $next .= "0\r\n\r\n";
            }
        }
        $request->__extend['request_buffer'] = $next;
        return true;
    }

    /**
     * 发送数据到 socket 请求创建代理通道
     * @param Request $request
     * @param resource $socket
     * @return bool|null
     */
    private static function writeProxy(Request $request, $socket)
    {
        $options = $request->__extend;
        $tunnel = ($proxy = $options['proxy']) ? ($request->__extend['request_tunnel'] ?? null) : true;
        // 不使用代理 或 代理通道已创建
        if (true === $tunnel) {
            return null;
        }
        // 发送代理服务器所需数据, 完成通道创建
        if (null === $tunnel) {
            $urls = $options['urls'];
            $host = $urls->host.':'.$urls->port;
            if ('http' === $proxy['scheme']) {
                // http 代理: 请求使用 CONNECT 创建通道
                $tunnel = [
                    sprintf('CONNECT %s HTTP/%s', $host, $options['version']),
                    'Host: '.$host,
                    'Proxy-Connection: Keep-Alive'
                ];
                if ($proxy['user']) {
                    $tunnel[] = 'Proxy-Authorization: '.Helper::getBasicAuth($proxy['user'], $proxy['pass']);
                }
                $tunnel = trim(implode("\r\n", $tunnel)). "\r\n\r\n";
                static::debug($request, '* Establish HTTP proxy tunnel to '.$host);
                static::debug($request, '> '.$tunnel, false);

            } elseif(($socks4 = 'socks5' !== $proxy['scheme']) || isset($options['request_tunnel_socks5'])) {
                if (!$socks4 && $options['request_tunnel_socks5']) {
                    // socks5 第二步: 需要认证, 发送帐号密码 (若无需认证, 会跳过该步骤, 直接进入下一步)
                    $tunnel = pack('C2', 1, strlen($proxy['user'])) . $proxy['user'] .
                        pack('C', strlen($proxy['pass'])) . $proxy['pass'];
                    static::debug($request, '* Send auth to SOCKS5 proxy server');

                } else {
                    // socks4 或 socks5 认证后: 请求创建通道
                    $ip = null;
                    // socks4 协议可能不支持直接使用域名, 若能获取到 IP, 直接使用 IP
                    // socket4a 或 socks5 可以使用域名, 直接使用域名, 这样代理服务器可以解析到就近的 dns 节点
                    if ('socks4' === $proxy['scheme']) {
                        $ip = gethostbyname($urls->host);
                        if ($ip === $urls->host) {
                            $ip = null;
                        }
                    }
                    if ($socks4) {
                        // socks4: ip 获取失败的话使用 0.0.0.1, 同时添加域名到尾部, socks4a 支持该格式
                        $sip = $ip ? ip2long($ip) : null;
                        $tunnel = pack('C2nNC', $v = 4, 1, $urls->port, $sip ?: 1, 0) .
                            ($sip ? '' : $urls->host.pack('C', 0));

                    } else {
                        // socks5: ip 获取失败, 可直接使用域名创建通道
                        $tunnel = pack('C3', $v = 5, 1, 0);
                        if ($sip = $ip ? @inet_pton($ip) : null) {
                            $tunnel .= pack('C', false === strpos($sip, ':') ? 1 : 4) . $sip;
                        } else {
                            $tunnel .= pack('C2', 3, strlen($urls->host)) . $urls->host;
                        }
                        $tunnel .= pack('n', $urls->port);
                    }
                    static::debug($request, '* SOCKS'.$v.' proxy connect to '.
                        ($sip ? 'IPv4 '.$ip.' (locally resolved)' : $host.' (server resolved)'));
                }
            } else {
                // socks5 第一步: 发送数据判断代理是否需要认证, 是否接受普通账号密码认证
                $tunnel = pack('C', 5).(
                    $proxy['user'] ?  pack('C3', 2, 2, 0) : pack('C2', 1, 0)
                );
            }
        }
        // 发送代理服务器所需数据, 同样的, 可能会发送失败或发送不完整, 尝试多次, 请求已发送完毕, 移除写入状态监测, 等待代理响应
        if (!empty($tunnel)) {
            $len = static::syncSocket($request, $socket, $tunnel);
            $writeOver = $len === strlen($tunnel);
            $request->__extend['request_tunnel'] = $writeOver ? '' : substr($tunnel, $len);
            if (!$writeOver) {
                return true;
            }
        }
        return false;
    }

    /**
     * 从 socket 读取 $request 的 response
     * @param Request $request
     * @param $socket
     * @return resource|bool|string
     * @throws Throwable
     */
    private static function readSocket(Request $request, $socket)
    {
        // 等待代理通道响应 CONNECT 请求
        if (null !== $proxyRead = static::readProxy($request, $socket)) {
            return $proxyRead;
        }
        // 首次读取, 重置 response 并先读取 header
        if (null === $buffer = $request->__extend['response_buffer'] ?? null) {
            $request->response->__setResponseValue('start', true);
            $buffer = $request->__extend['response_buffer'] = ['header', 0];
        }
        // 处理现阶段读取的数据
        $type = $buffer[0];
        if ('chunk' === $type) {
            $wait = static::readChunkBody($request, $socket);
        } elseif ('stream' === $type) {
            $wait = static::readStreamBody($request, $socket);
        } else {
            $wait = static::readHeaders($request, $socket);
        }
        if ($wait) {
            return true;
        }
        // 若结束, 移除临时缓存, 处理 socket 接收数据
        unset($request->__extend['response_buffer']);
        return static::resolveSocket($request, $socket);
    }

    /**
     * 接收 socket 数据完成代理通道创建
     * @param Request $request
     * @param resource $socket
     * @return resource|true|null
     */
    private static function readProxy(Request $request, $socket)
    {
        $options = $request->__extend;
        $tunnel = ($proxy = $options['proxy']) ? ($request->__extend['request_tunnel'] ?? null) : true;
        // 不使用代理 或 代理通道已创建
        if (true === $tunnel) {
            return null;
        }
        $connected = false;
        $buffer = static::syncSocket($request, $socket);
        $buffer = ($options['response_tunnel'] ?? '').$buffer;
        if ('http' === $proxy['scheme']) {
            // http 代理: 处理通道创建结果
            static::debug($request, $buffer);
            if (0 === strpos($buffer, 'HTTP/') && false !== $pos = strpos($buffer, "\r\n\r\n")) {
                $buffer = substr($buffer, 0, $pos);
                static::debug($request, '< '.str_replace("\r\n", "\r\n< ", trim($buffer))."\r\n<");
                if (200 !== $code = (int) explode(' ', $buffer)[1]) {
                    static::debug($request, '* CONNECT phase failed!');
                    throw new TransferException('Http proxy server response code ['.$code.']');
                }
                $connected = true;
            }

        } elseif(($socks4 = 'socks5' !== $proxy['scheme']) || isset($options['request_tunnel_socks5'])) {
            if (!$socks4 && true === $options['request_tunnel_socks5']) {
                // socks5 第二步: 判断认证结果, 若成功, 写入请求创建通道的数据
                if (strlen($buffer) === 2) {
                    $data = unpack('CVersion/CStatus', $buffer);
                    if (1 !== $data['Version'] || 0 !== $data['Status']) {
                        throw new TransferException('Socks5 proxy server authentication failed');
                    }
                    // 设置为 null, 继续写入
                    $request->__extend['request_tunnel'] = null;
                    $request->__extend['request_tunnel_socks5'] = false;
                    return $socket;
                }

            } elseif ($socks4) {
                // socks4: 处理 socks 通道创建结果
                if (strlen($buffer) === 8) {
                    $data = unpack('CNull/CStatus/nPort/Nip', $buffer);
                    if (0 !== $data['Null'] || 90 !== $data['Status']) {
                        throw new TransferException('Invalid '.$proxy['scheme'].' proxy server response');
                    }
                    $connected = true;
                }

            } else {
                // socks5 最后一步: 处理 socks 通道创建结果
                if (is_bool($options['request_tunnel_socks5'])) {
                    if (4 < $length = strlen($buffer)) {
                        $data = unpack('C4', substr($buffer, 0, 4));
                        if (5 !== $data[1] || 0 !== $data[2] || 0 !== $data[3]) {
                            throw new TransferException('Invalid socks5 proxy server response');
                        }
                        $type = $data[4];
                        if (1 === $type) {
                            $len = 4;
                        } elseif (4 === $type) {
                            $len = 16;
                        } elseif (3 === $type) {
                            $test = unpack('C', substr($buffer, 4, 1));
                            $len = $test[1];
                        } else {
                            throw new TransferException('Invalid socks5 proxy server address type');
                        }
                        $len = $len + 6;
                        if ($length < $len) {
                            $options['request_tunnel_socks5'] = $len;
                        } elseif ($length === $len) {
                            $connected = true;
                        } else {
                            throw new TransferException('Invalid socks5 proxy server response');
                        }
                    }
                } elseif (strlen($buffer) === $options['request_tunnel_socks5']) {
                    $connected = true;
                }
                if ($connected) {
                    // 移除为验证代理创建的临时变量
                    unset($request->__extend['request_tunnel_socks5']);
                }
            }
        } else {
            // socks5 第一步返回: 处理代理服务器所需认证方法
            if (strlen($buffer) === 2) {
                $data = unpack('CVersion/CMethod', $buffer);
                if (5 !== $data['Version']) {
                    throw new TransferException('Socks5 proxy server version/protocol mismatch');
                }
                // 获取结果成功, 开始写入通道创建请求
                $noAuth = 0 === $data['Method'];
                if ($noAuth || ($proxy['user'] && 2 === $data['Method'])) {
                    // 设置为 null, 可继续写入
                    $request->__extend['request_tunnel'] = null;
                    $request->__extend['request_tunnel_socks5'] = !$noAuth;
                    return $socket;
                }
                throw new TransferException('Unacceptable socks5 authentication method requested');
            }
        }
        // 通道创建成功, 准备 write 请求目标的数据到通道中
        if ($connected) {
            // 设置为 true, 代理通道处理完毕, 移除临时变量
            unset($request->__extend['response_tunnel']);
            $request->__extend['request_tunnel'] = true;
            static::debug($request, '* CONNECT phase completed!');
            static::enableProxySSL($request, $socket);
            return $socket;
        }
        // 继续等待代理通道响应
        $request->__extend['response_tunnel'] = $buffer;
        return true;
    }

    /**
     * 在代理服务器创建的通道中与目标网站进行 SSL 握手
     * @param Request $request
     * @param resource $socket
     */
    private static function enableProxySSL(Request $request, $socket)
    {
        $options = $request->__extend;
        $urls = $options['urls'];
        if (!$urls->secure) {
            return;
        }
        static::handleError($errorMsg, $errorCode);
        $enable = stream_set_blocking($socket, true)
            && false !== stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_ANY_CLIENT)
            && stream_set_blocking($socket, false);
        restore_error_handler();
        if (!$enable) {
            throw new TransferException(
                $errorMsg ?: 'HTTPS server through proxy: could not negotiate secure connection'
            );
        }
        static::parseCertInformation($request, $socket);
    }

    /**
     * 读取 response headers
     * @param Request $request
     * @param resource $socket
     * @return bool
     * @throws Throwable
     */
    private static function readHeaders(Request $request, $socket)
    {
        $response = $request->response;
        $start = $request->__extend['response_buffer'][1];
        while (true) {
            // 逐行读取 response header
            $line = static::syncSocket($request, $socket, true);
            // 无数据, 继续等待
            if (null === $line) {
                return true;
            }
            static::debug($request, '< '.$line, false);
            static::setRequestInfo($request, 'header_size', strlen($line), true);
            $header = $response->__putHeaderLine($line);
            if (null === $header) {
                throw new TransferException('Invalid http response header');
            }
            // onResponse
            if (!$start) {
                if (!is_array($header) || count($header) !== 2) {
                    throw new TransferException('Invalid http response header');
                }
                $header[] = $request;
                $request->__extend['response_buffer'][1] = $start = 1;
                static::setRequestTimeInfo($request, 'starttransfer_time');
                static::triggerCallbackFunction($request, 'onResponse', $header);
                continue;
            }
            // onHeaderLine
            if(is_array($header)) {
                if (!isset($request->__extend['reset'])) {
                    $key = key($header);
                    $value = current($header);
                    if ($request->autoCookie && 'Set-Cookie' === $key) {
                        Helper::setResponseCookie(
                            $value, $request->__extend['urls']->host, $request->__extend['cookieJar']
                        );
                    }
                    static::triggerCallbackFunction($request, 'onHeaderLine', [$key, $value, $request]);
                }
                continue;
            }
            // onHeader
            static::triggerCallbackFunction($request, 'onHeader', [$response->headers, $response->code, $request]);
            return static::readBody($request, $socket);
        }
    }

    /**
     * 读取 response body
     * @param Request $request
     * @param resource $socket
     * @return bool
     * @throws Throwable
     */
    private static function readBody(Request $request, $socket)
    {
        // head 请求, 不应返回 body
        if ($request->__extend['nobody']) {
            return false;
        }
        $request->__extend['response_start'] = microtime(true);
        $encoding = $request->response->headerFirst('Transfer-Encoding');
        if ($encoding) {
            if (false === stripos($encoding, 'chunked')) {
                throw new TransferException('Cannot handle "' . $encoding . '" transfer encoding');
            }
            $request->__extend['response_buffer'] = ['chunk', 0, '', '', null, false];
            static::writeResponseBody($request, '');
            return static::readChunkBody($request, $socket);
        }
        $length = $request->response->headerFirst('Content-Length');
        if (null === $length) {
            $length = 0;
            $zeroBody = false;
        } else {
            $length = (int) $length;
            $zeroBody = 0 === $length;
        }
        static::writeResponseBody($request, '', $length);
        // 响应明确指明 body length 为 0, 直接返回
        if ($zeroBody) {
            return false;
        }
        $request->__extend['response_buffer'] = ['stream', 0, $length];
        return static::readStreamBody($request, $socket);
    }

    /**
     * 读取数据流 body (长度可能未知，比如 http 1.0 可以在发送完 body 后关闭 socket)
     * @param Request $request
     * @param resource $socket
     * @return bool
     * @throws Throwable
     */
    private static function readStreamBody(Request $request, $socket)
    {
        list(, $download, $length) = $request->__extend['response_buffer'];
        $buffer = static::syncSocket($request, $socket, false, 0 === $length);
        // response 未指定 length + 服务端主动关闭 -> 认为请求完毕
        if (true === $buffer) {
            return false;
        }
        // 无数据, 继续等待
        if (null === $buffer) {
            return true;
        }
        $wait = true;
        $download += strlen($buffer);
        if ($length && $download >= $length) {
            // 若返回长度超过 header length? 截断
            if ($download > $length) {
                $buffer = substr($buffer, 0, $length - $download);
            }
            $wait = false;
            $download = $length;
        }
        $request->__extend['response_buffer'][1] = $download;
        static::writeResponseBody($request, $buffer, $length, $download);
        return $wait;
    }

    /**
     * 读取 chunked body
     * @param Request $request
     * @param resource $socket
     * @return bool
     * @throws Throwable
     */
    private static function readChunkBody(Request $request, $socket)
    {
        $buffer = static::syncSocket($request, $socket);
        // 无数据, 继续等待
        if (null === $buffer) {
            return true;
        }
        // 将上次遗留数据 + 本次接收数据 合并后进行处理
        list($type, $download, $chunked, $chunkHex, $chunkSize, $chunkLast) = $request->__extend['response_buffer'];
        $chunked .= $buffer;
        while (true) {
            // 处理: 分段开头 长度标记
            if (null === $chunkSize) {
                // 获取长度标记, 若没有数据, [跳出] 继续等待
                $len = strlen($chunked);
                if (!$len) {
                    break;
                }
                $i = 0;
                $getChunk = false;
                while ($i < $len) {
                    $chr = $chunked[$i];
                    if ("\r" === $chr) {
                        $getChunk = true;
                        break;
                    }
                    if (!ctype_xdigit($chr)) {
                        $chr = json_encode($chr);
                        throw new TransferException('Invalid chunk size '.$chr.' unable to read chunked body');
                    }
                    $chunkHex .= $chr;
                    $i++;
                }
                // 第一个字符就是 "\r"
                if (!$i) {
                    throw new TransferException('Malformed encoding found in chunked-encoding');
                }
                // 十六机制的长度值大于8位, 太长了, 不处理了
                if (strlen($chunkHex) > 8) {
                    throw new TransferException('Too long hexadecimal number in chunked-encoding');
                }
                $chunked = substr($chunked, $i);
                // 仍未获得长度标记, [跳出] 继续等待
                if (!$getChunk) {
                    break;
                }
                // [转到] 换行符处理
                $chunkSize = false;
            }
            // 处理: 换行符
            if (false === $chunkSize) {
                $eof = substr($chunked, 0, 2);
                if (strlen($eof) < 2) {
                    // [跳出] 继续等待
                    break;
                }
                if ("\r\n" !== $eof) {
                    throw new TransferException('Malformed encoding found in chunked-encoding');
                }
                $chunked = substr($chunked, 2);
                // 是长度标记后面的换行符
                if ('' !== $chunkHex) {
                    $chunkSize = hexdec($chunkHex);
                    $chunkHex = '';
                    // 若是最后一段, 再等待一个换行符
                    if (0 === $chunkSize) {
                        $chunkLast = null;
                        $chunkSize = false;
                    } elseif ($chunkSize >= static::CHUNK_MAX_SIZE) {
                        throw new TransferException('Too long hexadecimal number in chunked-encoding');
                    }
                    // [转到] 分段数据处理 或 换行符处理
                    continue;
                }
                // 数据结尾换行符, 且是最后一段结束的换行符, 接收完毕
                if (null === $chunkLast) {
                    return false;
                }
                // 数据结尾换行符, [转到] 分段开头获取
                $chunkSize = null;
                continue;
            }
            // 处理: 分段数据
            $content = substr($chunked, 0, $chunkSize);
            $download += $contentSize = strlen($content);
            static::writeResponseBody($request, $content, 0, $download);
            $chunked = substr($chunked, $contentSize);
            // 已接收数据不够本次分段用, [跳出] 继续接收数据
            if ($contentSize < $chunkSize) {
                $chunkSize -= $contentSize;
                break;
            }
            // 够用了, [转到] 换行符处理
            $chunkSize = false;
        }
        $request->__extend['response_buffer'] = [$type, $download, $chunked, $chunkHex, $chunkSize, $chunkLast];
        return true;
    }

    /**
     * 将读取到的 body 写入到 response
     * @param Request $request
     * @param ?string $content
     * @param int $total
     * @param int $download
     * @return int|false
     * @throws Throwable
     */
    private static function writeResponseBody(Request $request, ?string $content, int $total = 0, int $download = 0)
    {
        // 若 request 已被重置, 发起了新请求, 或 30X 重定向, 无需写入到 body
        if (isset($request->__extend['reset']) || isset($request->__extend['redirect'])) {
            return 0;
        }
        static::setRequestInfo($request, 'size_download', $download, true);
        static::triggerCallbackFunction($request, 'onDownload', [$download, $total, $request], false);
        if (strlen($content)) {
            $response = $request->response;
            if (!$response->charset && false !== stripos($response->contentType, 'text/htm') &&
                preg_match("/<meta(.*?)\s+charset=(\"|'|\s)?(.*?)(?=\"|'|\s)[^<>]+>/i", $content, $charset)
            ) {
                $response->__setResponseValue('charset', $charset[3]);
            }
            // encoding: decode compress content
            if (!isset($request->__extend['inflate'])) {
                $inflate = false;
                if (($request->__extend['encoding'] ?? false) &&
                    ($encoding = $response->headerFirst('Content-Encoding'))
                ) {
                    $encoding = strtolower($encoding);
                    $isGzip = 'gzip' === $encoding;
                    if ($isGzip || 'deflate' === $encoding) {
                        $inflate = inflate_init($isGzip ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_DEFLATE);
                    }
                }
                $request->__extend['inflate'] = $inflate;
            } else {
                $inflate = $request->__extend['inflate'];
            }
            if ($inflate) {
                static::handleError($errorMsg, $errorCode);
                $content = inflate_add($inflate, $content, ZLIB_NO_FLUSH);
                restore_error_handler();
                if ($errorMsg) {
                    throw new TransferException('Decode compress response: '.$errorMsg);
                }
            }
            return $response->body->write($content);
        }
        return 0;
    }

    /**
     * 触发回调函数
     * @param Request $request
     * @param string $event
     * @param array $args
     * @param bool $cloudReset
     * @return string|null
     * @throws Throwable
     */
    private static function triggerCallbackFunction(Request $request, string $event, array $args, bool $cloudReset = true)
    {
        // 若 request 已被重置, 发起了新请求, 不再执行回调
        $reset = null;
        if (!isset($request->__extend['reset']) && $callback = $request->{$event}) {
            try {
                $reset = call_user_func_array($callback, $args);
            } catch (Throwable $e) {
                static::throwCallbackException($request, $e, $event);
            }
        }
        if (!$cloudReset) {
            return null;
        }
        if (true !== $reset) {
            // onHeader 回调, 判断是否为 30X 重定向, 以便决定是否忽略后续的 response body
            if ('onHeader' === $event && $request->autoRedirect) {
                $code = $args[1];
                if (304 !== $code && 300 <= $code && 400 > $code && isset($args[0]['Location'])) {
                    $request->__extend['redirect'] = 1;
                }
            }
            return null;
        }
        $request->__extend['reset'] = $event;
        return $event;
    }

    /**
     * 设置 response error 为 callback 触发的异常
     * @param Request $request
     * @param Throwable $e
     * @param string $callback
     * @throws Throwable
     */
    private static function throwCallbackException(Request $request, Throwable $e, string $callback)
    {
        $request->response->__setResponseValue('isCallbackError', true);
        static::debug($request, '* Aborted on call '.$callback.' callback');
        throw $e;
    }

    /**
     * socket 请求结束, 处理本次请求 response 数据
     * @param Request $request
     * @param $socket
     * @return resource|string|false
     * @throws Throwable
     */
    private static function resolveSocket(Request $request, $socket)
    {
        $fd = (int) $socket;
        $options = $request->__extend;
        $urls = $options['urls'];
        $reqHeaders = $options['header'];
        $reset = $options['reset'] ?? null;

        $response = $request->response;
        $code = $response->code;

        // request 未在回调函数中发起新请求, 且为 30x 重定向
        if (isset($options['redirect'])) {
            // 重定向次数是否已达上限
            if ($request->maxRedirect) {
                $redirects = $response->redirects;
                $redirects = end($redirects);
                if ($redirects && $request->maxRedirect < count($redirects)) {
                    throw new TransferException('Maximum ('.$request->maxRedirect.') redirects followed');
                }
            }
            $url = Helper::getAbsoluteUrl(
                $response->headerFirst('Location'), $urls->scheme, $urls->host, $urls->port, $urls->path
            );
            // 触发重定向回调, 处理跳转后的 method/urls/headers/body/auth
            $reset = static::triggerCallbackFunction($request, 'onRedirect', [$url, $urls->url, $request]);
            if (!$reset) {
                unset($options['redirect']);
                // 不可用重用 request body 的 30x, 取消 body
                if ($options['body'] && !in_array($code, [300, 307, 308])) {
                    $options['body'] = null;
                    unset($reqHeaders['Content-Length'], $reqHeaders['Content-Type']);
                    $options['method'] = !$request->method || in_array($request->method, ['POST', 'PUT'])
                        ? 'GET' : $request->method;
                }
                // referer
                if ($request->autoReferrer) {
                    $reqHeaders['Referer'] = $urls->url;
                }
                // 转向 url / 请求 Authorization 处理
                $newUrl = (object) Helper::parseUrl($url);
                if (!isset($request->headers['Authorization'])) {
                    if ($newUrl->user) {
                        $options['authUser'] = $newUrl->user;
                        $options['authPass'] = $newUrl->pass;
                        if(isset($reqHeaders['Authorization'])) {
                            unset($reqHeaders['Authorization']);
                        }
                    } elseif (!$request->alwaysAuth && $newUrl->host !== $urls->host) {
                        $reqHeaders['Authorization'] = '';
                    }
                }
                // 有可能是 Http1.0 此时 socket 已关闭, 无法重用
                $isAlive = static::isSocketAlive($socket);
                $options['urls'] = $newUrl;
                $options['header'] = $reqHeaders;
                static::updateRedirects($request, $newUrl->url);
                static::debug($request, "* Follow redirect location to URL: '$newUrl->url'");
                if ($urls->scheme !== $newUrl->scheme || $urls->host !== $newUrl->host ||
                    $urls->port !== $newUrl->port
                ) {
                    // 请求目标发送变动，必须创建新的连接
                    if ($isAlive) {
                        fclose($socket);
                    }
                    static::debug($request, "* Close Connection #$fd and open new connection");
                    static::setRequestSocketHash($request, $options);
                    $request->__extend = $options;
                    $response->__setResponseValue('tryTime', 0);
                    $socket = static::createSocket($request, true);
                } else {
                    // 可直接复用当前连接
                    if ($isAlive) {
                        static::debug(
                            $request, '* Re-using existing connection! (#'.$fd.') with host '.$newUrl->host
                        );
                    }
                    $request->__extend = $options;
                    $socket = $isAlive ? $socket : static::createSocket($request, true);
                }
                static::resetRequestInfo($request, $newUrl->url);
                return $socket;
            }
        }
        // request 未在回调函数中发起新请求
        if (!$reset) {
            // response 401 需要认证, 获取到 request auth 后直接再次请求
            if (401 === $code && !isset($reqHeaders['Authorization']) &&
                isset($options['authUser']) && ($wwwAuth = $response->headerFirst('WWW-Authenticate'))
            ) {
                if ($auth = Helper::getRequestAuth(
                    $response->body, $options['method'], $urls->path,
                    $wwwAuth, $options['authUser'], $options['authPass']
                )) {
                    // 有可能是 Http1.0 此时 socket 已关闭, 无法重用
                    $isAlive = static::isSocketAlive($socket);
                    $reqHeaders['Authorization'] = $auth;
                    $request->__extend['header'] = $reqHeaders;
                    static::debug(
                        $request,
                        "* Issue another request to this URL: '$urls->url'\r\n".
                        ($isAlive ? '* Re-using existing connection! (#'.$fd.') with host '.$urls->host."\r\n" : '').
                        "* Server auth using ".strstr($wwwAuth, ' ', true).
                        " with user '{$options['authUser']}'"
                    );
                    $response->body->truncate(0);
                    static::resetRequestInfo($request, $urls->url);
                    return $isAlive ? $socket : static::createSocket($request, true);
                }
            }
            // 非正常 response code
            if (400 <= $code && !$request->allowError) {
                throw new TransferException('The requested URL returned error: '.$code);
            }
            // 请求完成, 设置 info
            $size = $response->body ? $response->body->size() : 0;
            $speed = $size ? floor(
                $size / max(1, microtime(true) - $request->__extend['response_start'])
            ) : 0;
            list($address, $port) = explode(':', stream_socket_get_name($socket, false));
            static::setRequestTimeInfo($request, 'total_time');
            static::setRequestInfo($request, [
                'local_ip' => $address,
                'local_port' => $port,
                'http_code' => $response->code,
                'content_type' => $response->contentType,
                'size_download' => $size,
                'speed_download' => $speed
            ]);
            $response->__setResponseValue('info', $request->__extend['info']);
            $reset = static::triggerCallbackFunction($request, 'onComplete', [$response, $request]);
        }
        if ($reset) {
            unset($request->__extend['reset']);
        }
        if (!$reset) {
            return false;
        }
        // 发起了新请求
        static::debug(
            $request, '* ['.$reset.'] Callback triggered a new request URL: \''.$request->url.'\''
        );
        if ($response->__mayLoop()) {
            $msg = 'The request may be stuck in an endless loop';
            static::debug($request, "* ".$msg."\n* Closing connection");
            throw new TransferException($msg);
        }
        $response->__setResponseValue('tryTime', 0);
        return $reset;
    }

    /**
     * 更新 response redirects
     * @param Request $request
     * @param string $url
     * @param bool $reset
     */
    private static function updateRedirects(Request $request, string $url, bool $reset = false)
    {
        $redirects = $request->response->redirects;
        $last = $reset ? [] : (array_pop($redirects) ?: []);
        $last[] = $url;
        $redirects[] = $last;
        $request->response->__setResponseValue('url', $url)
            ->__setResponseValue('redirects', $redirects);
    }

    /**
     * 获取并缓存异常信息
     * @param null $errorMsg
     * @param null $errorCode
     */
    private static function handleError(&$errorMsg = null, &$errorCode = null)
    {
        set_error_handler(function($code, $msg) use (&$errorMsg, &$errorCode) {
            if (!$errorMsg) {
                $errorCode = $code;
                $errorMsg = trim($msg);
            }
        }, E_ALL);
    }

    /**
     * 判断 socket 是否仍然可读写
     * @param resource $socket
     * @return bool
     */
    private static function isSocketAlive($socket)
    {
        return is_resource($socket) && !feof($socket);
    }

    /**
     * 关闭 socket
     * @param Request $request
     * @param resource $socket
     * @param ?Throwable $exception
     */
    private static function closeSocket(Request $request, $socket, Throwable $exception = null)
    {
        if ($exception) {
            static::closeRequest($request, $exception);
        }
        if ($socket) {
            set_error_handler(function(){});
            fclose($socket);
            static::debug($request, '* Closing connection #'.(int) $socket."\r\n");
            restore_error_handler();
        }
    }

    /**
     * 关闭 $request 请求，可能是正常结束 或 因为异常结束
     * @param Request $request
     * @param ?Throwable $exception
     */
    private static function closeRequest(Request $request, Throwable $exception = null)
    {
        $request->__extend = [];
        $response = $request->response;
        $response->__setResponseValue('end', true);
        if ($exception) {
            static::debug($request, '* '.$exception->getMessage());
            Helper::setResponseException($request, $exception);
        }
    }

    /**
     * debug
     * @param Request $request
     * @param string $debug
     * @param bool $eof
     */
    private static function debug(Request $request, string $debug, bool $eof = true)
    {
        // 未启用 debug 或 request 已被重置, 就不再写入 debug 信息了
        if (!$request->debug || isset($request->__extend['reset'])) {
            return;
        }
        if (!$request->response->debug) {
            if (true === $request->debug) {
                $stream = new Stream(fopen('php://temp', 'w+b'), 'r', true);
            } else {
                $stream = new Stream($request->debug, 'a+b');
            }
            $request->response->__setResponseValue('debug', $stream);
        }
        if ($debug = is_callable($debug) ? call_user_func($debug) : $debug) {
            $request->response->debug->write($debug.($eof ? "\r\n" : ''));
        }
    }
}
