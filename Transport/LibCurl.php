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

class LibCurl implements TransportInterface
{
    /**
     * Curl 连接失败的错误码
     * @var array
     */
    private static $connectionErrors = [
        CURLE_GOT_NOTHING          => true,
        CURLE_COULDNT_CONNECT      => true,
        CURLE_SSL_CONNECT_ERROR    => true,
        CURLE_OPERATION_TIMEOUTED  => true,
        CURLE_COULDNT_RESOLVE_HOST => true,
    ];

    /**
     * 不能发送 request body 的 method.
     * RFC 标准对于实体消息，只有两个明确规定:
     *   1. HEAD 请求不能返回实体消息
     *   2. TRACE 请求不能发送实体消息
     * 在实际使用中，使用 CONNECT HEAD GET DELETE 一般也不应发送实体消息，服务端可能会拒绝请求。
     * 这里仅将较为常用的 HEAD GET 列为无实体消息请求，其他 Method 不进行强制。
     * https://httpwg.org/specs/rfc7231.html#rfc.section.4.3.8
     * @var array
     */
    private static $nobodyMethods = ['TRACE', 'HEAD', 'GET'];

    /**
     * CA 证书路径
     * @var string
     */
    private static $caPath;

    /**
     * 执行前记录当前 max_execution_time 值
     * @var int
     */
    private $maxExecuteTime;

    /**
     * 已创建的 Curl socket
     * @var array
     */
    private $sockets = [];

    /**
     * @var null
     */
    private $curlMulti = null;

    /**
     * 是否正在关闭，避免同时执行关闭动作
     * @var bool
     */
    private $closing = false;

    /**
     * LibCurl constructor.
     */
    public function __construct()
    {
        // windows 环境若未设置 curl.cainfo 缺省使用本地证书
        $this->maxExecuteTime = (int) ini_get('max_execution_time');
        if (!self::$caPath && DIRECTORY_SEPARATOR == '\\' && !ini_get('curl.cainfo')) {
            self::$caPath = Helper::getCaPath();
        }
    }

    /**
     * 对象销毁时关闭连接
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * @inheritdoc
     */
    public function fetch(Request $request)
    {
        set_time_limit(0);
        if ($socket = $this->createSocket($request)) {
            while (true) {
                curl_exec($socket);
                if (null !== static::resolveCurl($request, $socket, curl_errno($socket))) {
                    break;
                }
            }
        }
        // $socket 使用完, 放回连接池
        $this->reuseSocket($request, $socket);
        if ($this->maxExecuteTime) {
            set_time_limit($this->maxExecuteTime);
        }
        return $request->response;
    }

    /**
     * @inheritdoc
     */
    public function fetchMulti(array $requests, int $maxConnection = 0)
    {
        set_time_limit(0);
        $maxConnection = $maxConnection ?: Helper::MAX_OPEN_SOCKET;
        $multi = $this->curlMulti ?: ($this->curlMulti = curl_multi_init());
        $count = 0;
        $success = 0;
        $collection = [];
        while ($request = array_shift($requests)) {
            if ($socket = static::createSocket($request)) {
                $collection[(int) $socket] = $request;
                curl_multi_add_handle($multi, $socket);
                if (++$count >= $maxConnection) {
                    break;
                }
            }
        }
        while (true) {
            // 运行并发请求句柄
            do {
                $status = curl_multi_exec($multi, $running);
            } while (CURLM_CALL_MULTI_PERFORM === $status);
            if (CURLM_OK !== $status) {
                throw (new TransferException(curl_multi_strerror($status)))->setError($status);
            }
            // 空闲状态
            if (-1 === curl_multi_select($multi)) {
                usleep(6);
            }

            // 判断是否有已结束的连接, 并进行处理
            $added = false;
            do {
                $done = curl_multi_info_read($multi, $queue);
                $socket = $done && CURLMSG_DONE === $done['msg'] ? $done['handle'] : null;
                $request = $socket ? ($collection[(int) $socket] ?? null) : null;
                if (!$request) {
                    continue;
                }
                // 一个请求结束了, 若需再次请求, 放回请求池
                $resolve = static::resolveCurl($request, $socket, (int) $done['result']);
                curl_multi_remove_handle($multi, $socket);
                if (null === $resolve) {
                    curl_multi_add_handle($multi, $socket);
                    !$added && ($added = true);
                    continue;
                }
                // $socket 使用完, 放回连接池, 若请求成功, 计数递增
                $this->reuseSocket($request, $socket);
                if (true === $resolve) {
                    $success++;
                }
                // 参数 $requests 并发列队中还有剩余, 提取一个加入到请求池
                while ($request = array_shift($requests)) {
                    if ($socket = static::createSocket($request)) {
                        $collection[(int) $socket] = $request;
                        curl_multi_add_handle($multi, $socket);
                        !$added && ($added = true);
                        break;
                    }
                }
            } while($queue);

            // 全部请求结束且无再次入池
            if (!$added && !$running) {
                break;
            }
        }
        if ($this->maxExecuteTime) {
            set_time_limit($this->maxExecuteTime);
        }
        return $success;
    }

    /**
     * @inheritdoc
     */
    public function close()
    {
        if ($this->closing) {
            return $this;
        }
        $this->closing = true;
        // 关闭所有 socket
        while ($socket = array_shift($this->sockets)) {
            @curl_close($socket);
        }
        // 关闭并发句柄
        if ($this->curlMulti) {
            @curl_multi_close($this->curlMulti);
            $this->curlMulti = null;
        }
        $this->closing = false;
        return $this;
    }

    /**
     * 请求完 $request 后, 将 $socket 放回连接池复用
     * @param Request $request
     * @param \CurlHandle|resource $socket
     */
    private function reuseSocket(Request $request, $socket)
    {
        if (!$socket) {
            return;
        }
        // 实测发现, libcurl 只能进行一次包含 auth 的请求, 当再次请求, auth 配置无法重用
        // 为避免这种请情况导致的请求失败, 对于牵涉 auth 的请求, 不重用 socket
        $response = $request->response;
        if ($request->auth || $request->user || 401 === $response->code) {
            @curl_close($socket);
            return;
        }
        $url = $response->url ?: null;
        $url = $url ? parse_url($url) : null;
        if ($url && isset($url['host'])) {
            $host = $url['host'].($url['port'] ?? '');
            $this->sockets[$host] = $socket;
        } else {
            $this->sockets[] = $socket;
        }
    }

    /**
     * 由 $request 创建 socket
     * @param Request $request
     * @return \CurlHandle|resource|false
     */
    private function createSocket(Request $request)
    {
        try {
            // 尽量使用连接池中相同 Host 的, 可重用 TCP 通道
            $host = $request->host.$request->port;
            $socket = $this->sockets[$host] ?? null;
            if ($socket) {
                unset($this->sockets[$host]);
            } else {
                $socket = array_shift($this->sockets);
            }
            // 准备 curl 选项, 重置 socket
            $options = static::getCurlOptions($request->setResponse(new Response()), (bool) $socket);
            $socket = $socket ? static::resetSocket($socket) : curl_init();
            // 应用 curl 选项 到 socket
            if (!$socket || !curl_setopt_array($socket, $options)) {
                @curl_close($socket);
                throw (new TransferException('curl init failed'))->setError(CURLE_FAILED_INIT);
            }
            return $socket;
        } catch (Throwable $e) {
            static::setException($request, $e);
            static::closeCurl($request);
            return false;
        }
    }

    /**
     * 重置 Curl socket 连接配置
     * @param \CurlHandle|resource $socket
     * @param ?array $options
     * @return resource
     */
    private static function resetSocket($socket, array $options = null)
    {
        curl_setopt($socket, CURLOPT_HEADERFUNCTION, null);
        curl_setopt($socket, CURLOPT_READFUNCTION, null);
        curl_setopt($socket, CURLOPT_WRITEFUNCTION, null);
        curl_setopt($socket, CURLOPT_PROGRESSFUNCTION, null);
        curl_reset($socket);
        if ($options) {
            curl_setopt_array($socket, $options);
        }
        return $socket;
    }

    /**
     * curl socket 请求结束 确认请求结果
     * - true:  请求成功
     * - false: 请求失败
     * - null:  使用 $socket 需再次发起请求(可能是重定向或网络错误), 此时 $socket 已设置好新的配置选项
     * @param Request $request
     * @param \CurlHandle|resource $socket
     * @param mixed $error
     * @return bool|null
     */
    private static function resolveCurl(Request $request, $socket, $error)
    {
        $response = $request->response;
        // curl 是因为在 callback 中发起新请求导致的失败
        if ($error && isset($request->__extend['#reset'])) {
            static::removeRequestHeader($request, ['#reset']);
            $error = null;
        }
        // curl 请求失败
        if ($error) {
            // 因没有 CA 证书导致请求失败, 重试
            if (CURLE_SSL_CACERT === $error && !isset($request->__extend['#withca'])) {
                $request->__extend['#withca'] = 1;
                curl_setopt($socket, CURLOPT_CAINFO, self::$caPath = Helper::getCaPath());
                return null;
            }
            // 因网络问题导致的请求失败, 重试
            if (isset(self::$connectionErrors[$error]) && $response->tryTime < $request->tryTime) {
                $response->__setResponseValue('tryTime', $response->tryTime + 1);
                return null;
            }
            // 无法重试, 返回失败, 若 response 已设置过异常信息, 这里不再覆盖
            if (!$request->response->error) {
                static::setException($request, (new TransferException(curl_error($socket)))->setError($error));
            }
            static::closeCurl($request);
            return false;
        }
        try {
            // curl 请求成功, 判断是否 重定向 或 发起了新请求
            $response->__setResponseValue('info', $info = curl_getinfo($socket));
            if (null === $result = static::checkResponse($request, $socket, $info)) {
                return null;
            }
            static::closeCurl($request);
            return $result;
        } catch (Throwable $e) {
            static::setException($request, $e);
            static::closeCurl($request);
            return false;
        }
    }

    /**
     * 设置 $request 请求异常
     * @param Request $request
     * @param Throwable $exception
     * @param bool $callbackError
     */
    private static function setException(Request $request, Throwable $exception, bool $callbackError = false)
    {
        Helper::setResponseException($request, $exception, $callbackError);
    }

    /**
     * $request 请求结束
     * @param Request $request
     */
    private static function closeCurl(Request $request)
    {
        $request->__extend = [];
        $request->response->__setResponseValue('end', true);
    }

    /**
     * Curl 请求结束, 检测 response
     * - true:  请求成功
     * - false: 请求失败
     * - null:  需再次发起请求
     * @param Request $request
     * @param resource $socket
     * @param array $info
     * @return bool
     */
    private static function checkResponse(Request $request, $socket, array $info = [])
    {
        $response = $request->response;
        $redirect = $redirectTrigger = $request->__extend['#redirect'] ?? null;

        // 正常的请求结束
        if (null === $redirect) {
            $reset = null;
            if ($request->onComplete) {
                try {
                    $reset = call_user_func($request->onComplete, $response, $request);
                } catch (Throwable $e) {
                    static::setException($request, $e, true);
                    return false;
                }
            }
            if (true !== $reset) {
                return true;
            }
            // 在 onComplete 回调中发起了新请求
            $redirectTrigger = 'onComplete';
        }

        /*
         * 响应为 30x, 或在 header 回调函数中发起了新请求，可在 CURLOPT_HEADERFUNCTION 直接对 Curl 进行配置
         * 虽勉强能达到目的, 但存在以下问题:
         *
         * 一: 部分配置项无法直接重置
         *   若在 header 相关回调函数中修改 $request, 使用新配置重新发起请求
         *   有些 curl 选项无法直接重置, 必须先 curl_reset, 然后在应用新配置, 如 sslCert
         *   但 curl_reset 无法在 CURLOPT_HEADERFUNCTION 函数内使用, 该方案走不通
         *
         * 二: 重定向问题: Curl 自带了 CURLOPT_FOLLOWLOCATION 配置, 可以自动跟随 30x 请求, 但有以下问题
         *   1. 根据 RFC 规则, 只有 303 跳转才需要修改 Method 为 GET,
         *     其他 30x 应该继续使用原 method, 如有 request body, 也应继续发送
         *     但实际上, 浏览器只会对 300,307,308 这么做; 其他 30x 在跳转后, POST/PUT 会转为 GET 方式, 且不会发送 request body
         *     而 libcurl 库, 对于非 300,307,308 重定向, 不会改变 Method, 但也不发送 request body
         *     另外有可能在 30x 跳转前, 在发送 body 的同时, 手动设置了一些 Header
         *     这些 header (如 Content-Length) 与 body 不匹配, 会导致请求失败
         *  2. 重定向后的 request body 问题
         *     对于 300,307,308 重定向, 需要在跳转后继续发送 body
         *     若使用 CURLOPT_READFUNCTION 发送请求 body, 由于重定向前已发送 body
         *     libcurl 在重新发送前会调用 CURLOPT_SEEKFUNCTION 将 body 重新定位到开头再发送
         *     但 php 的 curl 没有实现该配置, 这会导致 'necessary data rewind wasn't possible' 错误
         *
         * 若不嫌麻烦, 可以在 CURLOPT_HEADERFUNCTION 判断是否属于以上情况, 若不属于, 直接重置, 否则转到该函数处理
         * 但这样程序逻辑会非常复杂, 可能无法全面处理, 为避免可能的 bug, 简单起见, 采用重新发起请求的方式
         */

        // 30x 重定向
        if (true === $redirect) {
            // 重定向次数是否已达上限
            if ($request->maxRedirect) {
                $redirects = $response->redirects;
                $redirects = end($redirects);
                if ($redirects && $request->maxRedirect < count($redirects)) {
                    static::redirectException(
                        $request, 'Maximum ('.$request->maxRedirect.') redirects followed', CURLE_TOO_MANY_REDIRECTS
                    );
                }
            }
            // 获取重定向目标 url
            $url = $info['redirect_url'] ?? null;
            if (!$url) {
                static::redirectException($request, 'Followed redirect url is empty', CURLE_OUT_OF_MEMORY);
            }
            // 触发重定向回调
            $reset = null;
            if ($request->onRedirect) {
                try {
                    $reset = call_user_func($request->onRedirect, $url, $info['url'], $request);
                } catch (Throwable $e) {
                    static::setException($request, $e, true);
                    return false;
                }
            }
            // 是否在重定向回调中发起了新请求
            if (true === $reset) {
                $redirectTrigger = 'onRedirect';
            } else {
                $redirectTrigger = null;
                $redirect = ['url' => $url];
                static::addDebugMessage($request, '* Follow redirect location to URL: \''.$url.'\'');

                // 判断是否超时, 并重置超时时长
                $timeout = 0;
                if(isset($request->__extend[':start'])) {
                    $duration = microtime(true) - $request->__extend[':start'];
                    $timeout = $request->timeout - $duration;
                    if ($timeout <= 0) {
                        static::redirectException(
                            $request,
                            'Operation timed out after '.round($duration * 1000).' milliseconds',
                            CURLE_OPERATION_TIMEDOUT
                        );
                    }
                }
                $redirect['timeout'] = $timeout;

                // :nobody 设置为不需要发送 request body, 有可能为多次重定向, 若之前已经转为 nobody request, 无需多次设置
                if (!isset($request->__extend[':nobody']) && !in_array($response->code, [300, 307, 308])) {
                    $request->__extend[':nobody'] = 1;
                }
                if ($request->autoReferrer) {
                    $redirect['Referer'] = $info['url'];
                }
                if (!$request->alwaysAuth && (isset($request->__extend['Authorization']) || $request->auth)
                    && parse_url($url, PHP_URL_HOST) !== $request->host
                ) {
                    $redirect['Authorization'] = '';
                }
                $request->__extend['#redirect'] = $redirect;
            }
        }

        // 手动发起的新请求, 检测是否可能陷入死循环 (30x 有最大跳转次数做兜底)
        if ($redirectTrigger) {
            static::addDebugMessage(
                $request, '* ['.$redirectTrigger.'] Callback triggered a new request URL: \''.$request->url.'\''
            );
            if ($response->__mayLoop()) {
                $msg = 'The request may be stuck in an endless loop';
                static::addDebugMessage($request, "* ".$msg."\n* Closing connection");
                throw new TransferException($msg);
            }
            // 发起新请求, 重置一些 response 缓存值
            $response->__setResponseValue('tryTime', 0);
            static::removeRequestHeader($request, [':start', ':nobody', '#redirect']);
        }
        // 获取并设置新的 curl 选项, 重新发起请求
        static::resetSocket($socket, static::getCurlOptions($request));
        return null;
    }

    /**
     * 重定向失败
     * @param Request $request
     * @param string $msg
     * @param int $error
     */
    private static function redirectException(Request $request, string $msg, int $error)
    {
        static::addDebugMessage($request, "\n* ".$msg."\n* Closing connection");
        throw (new TransferException($msg))->setError($error);
    }

    /**
     * 设置 Curl 配置选项
     * @param Request $request
     * @param bool $reuse
     * @return array
     */
    private static function getCurlOptions(Request $request, bool $reuse = false)
    {
        $options = [
            CURLOPT_HEADER         => false,
            CURLOPT_RETURNTRANSFER => false,
        ];
        if (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        // autoCookie
        if ($request->autoCookie) {
            // 将响应的 set-cookie 保存到内存中, 若重定向或在回调中发起新请求, 可自动使用 cookie
            $options[CURLOPT_COOKIEFILE] = '';
            if ($reuse) {
                // 重用上一个 $request 使用的 socket, 此时 socket 中仍有可能缓存着上次的 server cookie
                // 由于是不同的 request, 不符合使用逻辑, 所以这里先清空已缓存在内存的 cookie
                $options[CURLOPT_COOKIELIST] = 'ALL';
            }
        } else {
            // 不自动使用内存中的 cookie, 有可能是重用 socket, 需要先将之前内容中缓存的清除
            // 并且这里不再设置 CURLOPT_COOKIEFILE, 即从本次请求开始, 后续请求都不要缓存 set-cookie 了
            $options[CURLOPT_COOKIELIST] = 'ALL';
        }
        $options[CURLOPT_HEADERFUNCTION] = function($socket, $header) use ($request) {
            try {
                return static::onResponseHeader($request, $header, $socket);
            } catch (Throwable $e) {
                static::setException($request, $e, true);
                return -1;
            }
        };
        static::setRequestOptions($request, $options);
        return $options;
    }

    /**
     * Curl 选项中的 response header 回调
     * @param Request $request
     * @param string $header
     * @param \CurlHandle|resource $socket
     * @return int
     */
    private static function onResponseHeader(Request $request, string $header, $socket)
    {
        $length = strlen($header);
        $response = $request->response;
        $headerValue = $response->__putHeaderLine($header);
        if (null === $headerValue) {
            return $length;
        }
        $reset = false;
        // header 接收完毕
        if (true === $headerValue) {
            $code = $response->code;
            $headers = $response->headers;
            if ($request->onHeader) {
                $reset = call_user_func($request->onHeader, $headers, $code, $request);
            }
            if (true === $reset) {
                $reset = 'onHeader';
            } elseif ($request->autoRedirect && 304 !== $code
                && 300 <= $code && 400 > $code && isset($headers['Location'])
            ) {
                // 跟踪 30X 重定向响应, 不再接收 response body
                $request->__extend['#redirect'] = true;
                curl_setopt($socket, CURLOPT_NOBODY, true);
                static::removeRequestHeader($request, '#download');
            }
        }
        // 读取到 status code / http version, 并触发回调
        elseif (count($headerValue) > 1) {
            if ($request->onResponse) {
                $reset = call_user_func($request->onResponse, $headerValue[0], $headerValue[1], $request);
            }
            if (true === $reset) {
                $reset = 'onResponse';
            }
        }
        // 触发单行 header 回调 (有可能在 onResponse onHeaderLine 时发起了新请求, 但 Curl 仍会把 header 读取完, 这里就不再触发回调了)
        elseif ($request->onHeaderLine && !isset($request->__extend['#redirect'])) {
            $reset = call_user_func($request->onHeaderLine, key($headerValue), current($headerValue), $request);
            if (true === $reset) {
                $reset = 'onHeaderLine';
            }
        }
        // 由回调函数触发重新请求, 不再接收 response body
        if ($reset) {
            $request->__extend['#reset'] = true;
            $request->__extend['#redirect'] = $reset;
            static::removeRequestHeader($request, '#download');
            return -1;
        }
        return $length;
    }

    /**
     * 设置与 $request 对象相关的 Curl 配置选项
     * @param Request $request
     * @param array $options
     */
    private static function setRequestOptions(Request $request, array &$options)
    {
        $extra = [];
        $timeout = 0;
        $reset = false;
        if (isset($request->__extend['#redirect'])) {
            // 重定向发起的请求
            $extra = $request->__extend['#redirect'];
            $url = $extra['url'];
            $timeout = $extra['timeout'];
            unset($extra['url'], $extra['timeout']);
        } else {
            // 主动发起的请求
            if (empty($request->host)) {
                throw new RequestException('Request url must be set.');
            }
            $url = $request->url;
            if (!empty($request->queries)) {
                $url .= (empty($request->urlQuery) ? '?' : '&') .
                    http_build_query($request->queries, null, '&', PHP_QUERY_RFC3986);
            }
            // 记录执行时间, 以便在下次重定向时判断是否 timeout
            $reset = true;
            if ($request->timeout > 0) {
                $timeout = $request->timeout;
                $request->__extend[':start'] = microtime(true);
            }
        }
        $options[CURLOPT_URL] = $url;
        $options[CURLOPT_TIMEOUT_MS] = $timeout ? max(1000, round($timeout * 1000)) : 0;

        // url, charset, redirects; 若是手动发起的新请求, 将上次跳转记录作为子数组记录下来; 重定向请求则直接追加即可
        $redirects = $request->response->redirects;
        $last = $reset ? [] : (array_pop($redirects) ?: []);
        $last[] = $url;
        $redirects[] = $last;
        $request->response->__setResponseValue('start', true)
            ->__setResponseValue('url', $url)
            ->__setResponseValue('charset', $request->charset)
            ->__setResponseValue('redirects', $redirects);

        // 提取到 header 到 __extend 临时变量中, 不污染 request 原始 header
        static::copyRequestHeader($request, $extra);

        // 设置 Request Curl 连接相关配置
        static::setConnectOptions($request, $options);

        // 设置 Request body 配置 (存在 :nobody 说明是响应重定向到了无需 request body 的 url)
        if (isset($request->__extend[':nobody'])) {
            $nobody = true;
            $method = in_array($request->method, ['POST', 'PUT']) ? 'GET' : $request->method;
        } else {
            $nobody = !static::setBodyOptions($request, $options);
            $method = $request->method ?: ($nobody ? 'GET' : 'POST');
        }
        if (!$nobody) {
            $request->__extend['#upload'] = 0;
        }
        $options[CURLOPT_CUSTOMREQUEST] = $method;

        // HEAD 请求，必须设置 CURLOPT_NOBODY 为 true, 不等待 Response body, 其他 method 则设置读取消息回调
        if ('HEAD' === $method) {
            $options[CURLOPT_NOBODY] = true;
        } else {
            $options[CURLOPT_NOBODY] = false;
            $request->__extend['#download'] = 0;
            static::setWriteFunction($request, $options);
        }

        // 设置 发送 Request body 和 读取 Response body 进度的回调
        static::setProgressFunction($request, $options);

        // request header
        $options[CURLOPT_HTTPHEADER] = static::makeRequestHeader($request);
    }

    /**
     * 设置受 $request 对象影响的 Curl 连接选项
     * @param Request $request
     * @param array $options
     */
    private static function setConnectOptions(Request $request, array &$options)
    {
        // version
        switch ($request->version) {
            case 2:
                $version = CURL_HTTP_VERSION_2_0;
                break;
            case 1.1:
                $version = CURL_HTTP_VERSION_1_1;
                break;
            case 1:
                $version = CURL_HTTP_VERSION_1_0;
                break;
            default:
                $version = CURL_HTTP_VERSION_NONE;
                break;
        }
        $options[CURLOPT_HTTP_VERSION] = $version;

        // tcp noDelay
        $options[CURLOPT_TCP_NODELAY] = !$request->tcpDelay;

        // allow error
        $options[CURLOPT_FAILONERROR] = !$request->allowError;

        // 不使用 curl 内置的跳转跟随
        $options[CURLOPT_FOLLOWLOCATION] = false;

        // cookie
        $cookie = $request->getHeader('Cookie');
        if ($cookie && is_array($cookie)) {
            $cookie = join('; ', $cookie);
        }
        if ($cookie) {
            $options[CURLOPT_COOKIE] = $cookie;
        }

        // force ip
        if (null !== $request->forceIp) {
            $options[CURLOPT_IPRESOLVE] = $request->forceIp ? CURL_IPRESOLVE_V4 : CURL_IPRESOLVE_V6;
        } else {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_WHATEVER;
        }

        // host resolve
        $options[CURLOPT_RESOLVE] = $request->hostResolver;

        // header: Accept-Encoding
        if (!isset($request->__extend['Accept-Encoding']) && $request->useEncoding) {
            $options[CURLOPT_ENCODING] = '';
        }

        // header: Authorization
        if (!isset($request->__extend['Authorization'])) {
            if ($auth = $request->auth) {
                list($user, $pass, $type) = array_pad($auth, 3, null);
                if (null === $type || Request::AUTH_ANY === $type) {
                    $type = CURLAUTH_ANY;
                } elseif (Request::AUTH_ANYSAFE === $type) {
                    $type = CURLAUTH_ANYSAFE;
                } else {
                    $newType = 0;
                    if (Request::AUTH_BASIC & $type) {
                        $newType |= CURLAUTH_BASIC;
                    }
                    if (Request::AUTH_DIGEST & $type) {
                        $newType |= CURLAUTH_DIGEST;
                    }
                    if (Request::AUTH_GSSNEGOTIATE & $type) {
                        $newType |= CURLAUTH_GSSNEGOTIATE;
                    }
                    if (Request::AUTH_NTLM & $type) {
                        $newType |= CURLAUTH_NTLM;
                    }
                    $type = $newType;
                }
                $options[CURLOPT_HTTPAUTH] = $type;
                $options[CURLOPT_USERPWD] = "$user:$pass";
            } else {
                $options[CURLOPT_USERPWD] = null;
            }
        }

        // sslVerify
        if ($request->sslVerify) {
            if (is_string($request->sslVerify)) {
                $request->__extend['#withca'] = 1;
                if (file_exists($request->sslVerify)) {
                    $options[CURLOPT_CAINFO] = $request->sslVerify;
                } elseif (is_dir($request->sslVerify)) {
                    $options[CURLOPT_CAPATH] = $request->sslVerify;
                } else {
                    throw new RequestException("SSL CA bundle not found: $request->sslVerify");
                }
            } elseif (self::$caPath) {
                $request->__extend['#withca'] = 1;
                $options[CURLOPT_CAINFO] = self::$caPath;
            }
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = $request->sslVerifyHost ? 2 : 0;
        } else {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        // sslCert: 该选项不支持 从已设置 -> 移除设置 的重置 (直接设置为 null 会报错)
        if (!empty($request->sslCert)) {
            list($cert, $key, $passwd) = array_pad($request->sslCert, 3, null);
            if (!$cert || !file_exists($cert)) {
                throw new RequestException("SSL certificate not found: $cert");
            }
            if ($key && !file_exists($key)) {
                throw new RequestException("SSL certificate key not found: $key");
            }
            $options[CURLOPT_SSLCERT] = $cert;
            if ($key) {
                $options[CURLOPT_SSLKEY] = $key;
            }
            $options[CURLOPT_KEYPASSWD] = $passwd;
            $options[CURLOPT_SSLKEYPASSWD] = $passwd;
        }

        // sslCaptureInfo
        $options[CURLOPT_CERTINFO] = $request->sslCaptureInfo;

        // proxy
        $proxy = Helper::getRequestProxy($request);
        if ($proxy) {
            $options[CURLOPT_PROXY] = $proxy['proxy'];
            // 使用隧道模式, 减少数据窃取风险
            $options[CURLOPT_HTTPPROXYTUNNEL] = true;
            if ($proxy['user']) {
                $options[CURLOPT_PROXYUSERPWD] = $proxy['user'].':'.$proxy['pass'];
                $options[CURLOPT_PROXYAUTH] = Request::AUTH_NTLM === $proxy['auth'] ? CURLAUTH_NTLM : CURLAUTH_BASIC;
            }
            if ($request->proxyHeader) {
                $options[CURLOPT_PROXYHEADER] = $request->proxyHeader;
            }
            // php7.3 以上版本, https 协议代理可设置证书相关配置
            if (PHP_VERSION_ID >= 70300) {
                if ($request->proxyVerify) {
                    if (is_string($request->proxyVerify)) {
                        $request->__extend['#withca'] = 1;
                        if (file_exists($request->proxyVerify)) {
                            $options[CURLOPT_PROXY_CAINFO] = $request->proxyVerify;
                        } elseif (is_dir($request->proxyVerify)) {
                            $options[CURLOPT_PROXY_CAPATH] = $request->proxyVerify;
                        } else {
                            throw new RequestException("Proxy SSL CA bundle not found: $request->proxyVerify");
                        }
                    } elseif (self::$caPath) {
                        $request->__extend['#withca'] = 1;
                        $options[CURLOPT_PROXY_CAINFO] = self::$caPath;
                    }
                    $options[CURLOPT_PROXY_SSL_VERIFYPEER] = true;
                    $options[CURLOPT_PROXY_SSL_VERIFYHOST] = $request->proxyVerifyHost ? 2 : 0;
                } else {
                    $options[CURLOPT_PROXY_SSL_VERIFYPEER] = false;
                    $options[CURLOPT_PROXY_SSL_VERIFYHOST] = 0;
                }
                if (!empty($request->proxyCert)) {
                    list($cert, $key, $passwd) = array_pad($request->proxyCert, 3, null);
                    if (!$cert || !file_exists($cert)) {
                        throw new RequestException("Proxy SSL certificate not found: $cert");
                    }
                    if ($key && !file_exists($key)) {
                        throw new RequestException("Proxy SSL certificate key not found: $key");
                    }
                    $options[CURLOPT_PROXY_SSLCERT] = $cert;
                    if ($key) {
                        $options[CURLOPT_PROXY_SSLKEY] = $key;
                    }
                    $options[CURLOPT_PROXY_KEYPASSWD] = $passwd;
                }
            }
        } else {
            $options[CURLOPT_PROXY] = null;
        }

        // debug
        if ($request->debug) {
            // 有可能在 onResponse 或 onHeader 回调中重置 Curl 配置参数
            // 需要判断 request debug 是否发生变化, 否则 debug 信息会写入到不同的 resource 中
            if (isset($request->__extend[':debug']) && $request->debug === $request->__extend[':debug']) {
                $stream = $request->__extend[':debug_stream'];
            } else {
                if (true === $request->debug) {
                    $stream = new Stream(fopen('php://temp', 'w+b'), 'r', true);
                } else {
                    $stream = new Stream($request->debug, 'a+b');
                }
                $request->__extend[':debug'] = $request->debug;
                $request->__extend[':debug_stream'] = $stream;
                if (!$stream->isWritable()) {
                    throw new RequestException("Debug resource is not writable: $request->debug");
                }
            }
            $resource = $stream->resource();
            @fseek($resource, 0, SEEK_END);
            $options[CURLOPT_VERBOSE] = true;
            $options[CURLOPT_STDERR] = $resource;
            $request->response->__setResponseValue('debug', $stream);
        } else {
            $options[CURLOPT_VERBOSE] = false;
        }
    }

    /**
     * 设置受 $request 对象影响的 Curl 上传选项, 需注意以下两点
     *  - put body / post body / no body 发送变动, 可以直接重置, 无需 curl_reset
     *  - 已知 length 的 put body -> 转为使用 chunked 的 put body, 无法直接重置, 必须 curl_reset
     * @param Request $request
     * @param array $options
     * @return bool
     */
    private static function setBodyOptions(Request $request, array &$options)
    {
        // 根据 request method 确定是否有必要设置 request body
        $body = in_array($request->method, self::$nobodyMethods)
            ? null : Helper::getRequestBody($request, $request->__extend);

        // 校验 body
        $stream = $size = $chunk = null;
        if ($body) {
            $stream = $body instanceof StreamInterface;
            $size = $stream ? $body->size() : strlen($body);
            $chunk = $stream && null === $size;
        }

        // 没有 size, 且不是 chunk, 认为是 no body
        // 若 method 是 put post, 需设置 Content-Length:0 明确告知服务端无 request body, 其他 method 移除 Content-Length
        if (!$size && !$chunk) {
            $options[CURLOPT_UPLOAD] = false;
            $request->__extend['Content-Length'] = in_array($request->method, ['PUT', 'POST']) ? 0 : '';
            return false;
        }

        // 移除手动设置的 Content-Length header
        static::removeRequestHeader($request, 'Content-Length');

        // request body 为 string 或 长度较短, 直接使用 string 发送
        // CURLOPT_POSTFIELDS 必须在 CURLOPT_UPLOAD 之后
        if (!$stream || (!$chunk && $size < 1000000)) {
            $options[CURLOPT_UPLOAD] = false;
            $options[CURLOPT_POSTFIELDS] = $stream ? $body->content() : $body;
            return true;
        }

        // stream body 长度较长 或 无法获取长度(会使用 Transfer-Encoding:chunked 上传, 需服务端支持)
        $body->rewind();
        $options[CURLOPT_UPLOAD] = true;
        if (!$chunk) {
            $options[CURLOPT_INFILESIZE] = $size;
        }
        $options[CURLOPT_READFUNCTION] = function ($ch, $fd, $length) use ($body, $request) {
            try {
                return $body->read($length);
            } catch (Throwable $e) {
                static::setException($request, $e);
                return -1;
            }
        };
        return  true;
    }

    /**
     * 设置 Curl Response 写入选项
     * @param Request $request
     * @param array $options
     */
    private static function setWriteFunction(Request $request, array &$options)
    {
        $force = false;
        // 若 request saveTo 参数发生变动, 强制重建 body stream
        $prevSaveTo = $request->__extend[':saveTo'] ?? null;
        if ($prevSaveTo !== $request->saveTo) {
            $force = true;
            $request->__extend[':saveTo'] = $request->saveTo;
        }
        Helper::setResponseBody($request, $force);
        $options[CURLOPT_WRITEFUNCTION] = function ($ch, $write) use ($request) {
            $response = $request->response;
            // 若未获取到 charset 且 response 为 htm -> 尝试从 meta 中提取
            if (!$response->charset && false !== stripos($response->contentType, 'text/htm')
                && preg_match("/<meta(.*?)\s+charset=(\"|'|\s)?(.*?)(?=\"|'|\s)[^<>]+>/i", $write, $charset)
            ) {
                $response->__setResponseValue('charset', $charset[3]);
            }
            try {
                return $response->body->write($write);
            } catch (Throwable $e) {
                static::setException($request, $e);
                return -1;
            }
        };
    }

    /**
     * 设置 Curl 上传/下载 进度监听函数
     * @param Request $request
     * @param array $options
     */
    private static function setProgressFunction(Request $request, array &$options)
    {
        $downloadCallback = $request->onUpload || $request->onDownload;
        if (!$downloadCallback) {
            $options[CURLOPT_NOPROGRESS] = true;
            return;
        }
        $options[CURLOPT_NOPROGRESS] = false;
        $options[CURLOPT_PROGRESSFUNCTION] = function () use ($request, $downloadCallback) {
            if (!$downloadCallback) {
                return 0;
            }
            // PHP 5.5 之后第一个参数为 resource
            // PHP 8.0 之后第一个参数为 CurlHandle
            $args = func_get_args();
            array_shift($args);
            if (!static::callProgressFunction($request, (int) $args[3], (int) $args[2], true)) {
                return -1;
            }
            if (!static::callProgressFunction($request, (int) $args[1], (int) $args[0])) {
                return -1;
            }
            return 0;
        };
    }

    /**
     * 触发上传或下载的回调函数
     * @param Request $request
     * @param int $progress
     * @param int $total
     * @param bool $upload
     * @return bool
     */
    private static function callProgressFunction(Request $request, int $progress, int $total, bool $upload = false)
    {
        // $total 可能为 0 (比如使用 chunked 方式上传或下载)
        $callback = $upload ? $request->onUpload : $request->onDownload;
        if (!$callback || ($progress <= 0 && $total <= 0)) {
            return true;
        }
        // 回调可能过于频繁, 这里缓存一下进度值, 发送变动才实际触发
        $key = $upload ? '#upload' : '#download';
        $current = $request->__extend[$key] ?? null;
        if (null === $current || $progress === $current) {
            return true;
        }
        $request->__extend[$key] = $progress;
        try {
            call_user_func($callback, $progress, $total, $request);
            if ($total > 0 && $progress >= $total) {
                static::removeRequestHeader($request, $key);
            }
            return true;
        } catch (Throwable $e) {
            static::setException($request, $e, true);
            return false;
        }
    }

    /**
     * 复制新的 header 到 __extend
     * - ":" 开头的临时变量, 在多次新请求中都应保留
     * - "#" 开头的临时变量, 仅在一次请求内保留(多次重定向仍算一次请求)
     * @param Request $request
     * @param array $extra
     */
    private static function copyRequestHeader(Request $request, array $extra = [])
    {
        // 一把情况下，服务端是应该支持多个相同 key 的 request header
        // 但也确实存在不支持的, 绝大多数 header 不应该使用相同 key, 一般也不会对响应造成实质影响
        // 但 cookie 较为特殊, 还牵涉到与 30x 跳转前 Set-Cookie 设置的 cookie 组合的情况
        // 所以这里不将 Cookie 字段配置到 Curl HEAD 中
        $headers = $request->headers;
        unset($headers['Cookie']);
        $request->__extend = array_merge($extra, $headers, array_filter($request->__extend, function ($v, $key) {
            return ':' === $key[0];
        }, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * 移除指定的 Request header
     * @param Request $request
     * @param array|string $key
     */
    private static function removeRequestHeader(Request $request, $key)
    {
        if (is_array($key)) {
            foreach ($key as $k) {
                unset($request->__extend[$k]);
            }
        } else {
            unset($request->__extend[$key]);
        }
    }

    /**
     * 创建 Request header array
     * @param Request $request
     * @return array
     */
    private static function makeRequestHeader(Request $request)
    {
        $headerLine = [];
        $headers = $request->__extend;
        foreach ($headers as $key => $value) {
            $first = $key[0];
            if (':' === $first || '#' === $first) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $v) {
                    $headerLine[] = $key . ': ' . $v;
                }
            } else {
                $headerLine[] = $key . ': ' . $value;
            }
        }
        return $headerLine;
    }

    /**
     * 添加 debug 信息
     * @param Request $request
     * @param string $message
     * @return bool
     */
    private static function addDebugMessage(Request $request, string $message)
    {
        if (empty($message)) {
            return false;
        }
        /** @var Stream $stream */
        $stream = $request->__extend[':debug_stream'] ?? null;
        if (!$stream) {
            return false;
        }
        try {
            $stream->write($message."\n");
            return true;
        } catch (RequestException $e) {
            return false;
        }
    }
}
