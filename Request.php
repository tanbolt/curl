<?php
namespace Tanbolt\Curl;

use Tanbolt\Curl\Exception\RequestException;
use Tanbolt\Curl\Exception\UserCanceledException;

/**
 * Class Curl
 * @package Tanbolt\Curl
 *
 * @property-read ?string $name 标识符
 * @property-read bool $tcpDelay TCP 连接是否采用 Delay 模式
 * @property-read float $timeout 超时时长
 * @property-read int $tryTime 若因为网络问题导致请求失败, 可重新尝试次数
 * @property-read ?string $charset 字符编码 (默认自动获取)
 * @property-read bool $useEncoding 开启压缩传输, 请求会发送 Accept-Encoding 首部
 * @property-read ?array $auth http authorization 身份验证信息
 * @property-read bool $alwaysAuth 重定向时, 仍然发送 authorization header, 即使主机名发生变化
 * @property-read bool $autoRedirect 自动跟踪 Location 重定向
 * @property-read int $maxRedirect 最大可重定向次数, 0 为不限
 * @property-read bool $autoReferrer 重定向时, 自动使用上一个连接作为 Header referer
 * @property-read bool $autoCookie 重定向时, 自动将之前响应的 Set-Cookie 作为请求 cookie
 * @property-read ?bool $forceIp 是否强制请求指定 ip (true: IPV4, false:IPV6, null:不强制)
 * @property-read bool $allowError 是否接受异常返回(etc: 403,404...)
 * @property-read resource|string|bool $debug debug 设置
 *
 * @property-read string|bool $sslVerify 对于 https 请求, 是否要验证对方证书, 域名。如果该值为 string, 代表需要验证且使用指定的 CA 证书
 * @property-read bool $sslVerifyHost 是否验证证书与域名匹配
 * @property-read ?array $sslCert 用于 SSL 双向验证的证书
 * @property-read bool $sslCaptureInfo 是否获取 SSL 证书信息
 *
 * @property-read ?array $proxy http代理
 * @property-read ?bool $useSystemProxy 是否使用系统代理
 * @property-read string|bool $proxyVerify 是否验证 https 代理的 CA。如果该值为 string, 代表需要验证且使用指定的 CA 证书
 * @property-read bool $proxyVerifyHost 是否验证代理服务器的证书与域名匹配
 * @property-read ?array $proxyCert 用于代理服务器 SSL 双向验证的证书
 * @property-read array $proxyHeader 传给代理的自定义 HTTP 头
 * @property-read array $hostResolver 自定义域名 host 对应的 ip, 功能类似于本地 host 配置
 *
 * @property-read ?string $method
 * @property-read float $version
 * @property-read ?string $url
 * @property-read string $scheme
 * @property-read ?string $host
 * @property-read ?string $port
 * @property-read ?string $user
 * @property-read ?string $pass
 * @property-read string $urlPath
 * @property-read ?string $urlQuery
 *
 * @property-read array $headers 请求 header
 * @property-read array $queries 请求参数
 * @property-read array $params 请求 form data
 * @property-read array $files 上传文件
 * @property-read StreamInterface|array|resource|string $body 请求正文消息
 * @property-read ?string $mimeType 请求 body 的 mimeType
 *
 * @property-read resource|string|null $saveTo 保存路径
 * @property-read bool $makeDirAuto 是否自动创建保存文件的文件夹
 *
 * @property-read ?callable $onResponse 响应开始时回调
 * @property-read ?callable $onHeaderLine 逐条收到 header 的回调
 * @property-read ?callable $onHeader 头部信息接收完毕后的回调
 * @property-read ?callable $onRedirect 发生重定向时的回调
 * @property-read ?callable $onUpload 上传文件的进度监听函数
 * @property-read ?callable $onDownload 下载请求消息正文进度的监听函数
 * @property-read ?callable $onComplete 请求结束时的回调
 * @property-read ?callable $onError 发生错误时的回调
 *
 * @property-read Response $response 当前 request 的 response
 */
class Request
{
    const AUTH_BASIC = 1;
    const AUTH_DIGEST = 2;
    const AUTH_GSSNEGOTIATE = 4;
    const AUTH_NTLM = 8;
    const AUTH_ANY = 0xFFFF;
    const AUTH_ANYSAFE = 0xFFFE;

    private $name = null;
    private $tcpDelay = false;
    private $timeout = 120;
    private $tryTime = 0;
    private $charset = null;
    private $useEncoding = false;
    private $auth = null;
    private $alwaysAuth = false;
    private $autoRedirect = true;
    private $maxRedirect = 5;
    private $autoReferrer = true;
    private $autoCookie = true;
    private $forceIp = null;
    private $allowError = false;
    private $debug = false;

    private $sslVerify = true;
    private $sslVerifyHost = true;
    private $sslCert = null;
    private $sslCaptureInfo = false;

    private $proxy = null;
    private $useSystemProxy = null;
    private $proxyVerify = false;
    private $proxyVerifyHost = false;
    private $proxyCert = null;
    private $proxyHeader = [];
    private $hostResolver = [];

    private $method = null;
    private $version = 0;
    private $url = null;
    private $scheme = 'http';
    private $host = null;
    private $port = 80;
    private $user = null;
    private $pass = null;
    private $urlPath = '/';
    private $urlQuery = null;

    private $headers = [];
    private $queries = [];
    private $params = [];
    private $files = [];
    private $body = null;
    private $mimeType = null;

    private $makeDirAuto = true;
    private $saveTo = null;

    private $onResponse = null;
    private $onHeaderLine = null;
    private $onHeader = null;
    private $onRedirect = null;
    private $onUpload = null;
    private $onDownload = null;
    private $onComplete = null;
    private $onError = null;

    private $response = null;

    /**
     * 临时变量容器, 方便在 TransportInterface 对象中使用, 外部不能使用
     * @var array
     */
    public $__extend = [];

    /**
     * 初始化变量容器
     * @var array
     */
    private static $initProps;

    /**
     * Request constructor.
     * @param string|null $url
     * @param string|array|null $methodOrOptions
     */
    public function __construct(string $url = null, $methodOrOptions = null)
    {
        if (!self::$initProps) {
            $initProps = [];
            $default = get_object_vars($this);
            $ignore = [
                'scheme', 'host', 'port', 'user', 'pass', 'urlPath', 'urlQuery',
                'initProps', 'response', '__extend'
            ];
            foreach ($default as $k => $v) {
                if (!in_array($k, $ignore)) {
                    $initProps[$k] = $v;
                }
            }
            self::$initProps = $initProps;
        }
        if ($url) {
            $this->url($url);
        }
        if ($methodOrOptions) {
            if (is_array($methodOrOptions)) {
                $this->reset($methodOrOptions);
            } else {
                $this->method($methodOrOptions);
            }
        }
    }

    /**
     * 恢复为初始配置, 同时可以通过 options 覆盖指定的设置
     * @param array $options
     * @return $this
     */
    public function reset(array $options = [])
    {
        // 恢复为初始态
        foreach (self::$initProps as $k => $v) {
            if ('url' === $k) {
                $this->url($v);
            } else {
                $this->{$k} = $v;
            }
        }
        if (!$options) {
            return $this;
        }
        // 不可用直接使用方法设置的属性
        $noMethod = ['sslVerifyHost', 'proxyVerifyHost', 'mimeType'];
        // 这些属性需要使用数组参数
        $shouldArrParam = ['auth', 'sslVerify', 'sslCert', 'proxy', 'proxyVerify', 'proxyCert', 'body'];
        foreach ($options as $k => $value) {
            // 有可能是拼写错误导致设置失败, 这里抛错予以提示
            if (in_array($k, $noMethod) || !array_key_exists($k, self::$initProps)) {
                throw new RequestException('Unknown option: ['.$k.']');
            }
            if ('forceIp' === $k) {
                $this->forceIp = null === $value ? null : (bool) $value;
            } elseif (in_array($k, $shouldArrParam)) {
                $value = is_array($value) ? $value : [$value];
                call_user_func_array([$this, $k], $value);
            } else {
                call_user_func([$this, $k], $value);
            }
        }
        return $this;
    }

    /**
     * 标识符, 采用多进程并发请求时候, 设置 name 可以在返回结果中找对对应的请求结果
     * @param string $name
     * @return $this
     */
    public function name(string $name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * TCP 连接是否采用 Delay 模式，默认为 false
     * @param bool $tcpDelay
     * @return $this
     */
    public function tcpDelay(bool $tcpDelay = true)
    {
        $this->tcpDelay = $tcpDelay;
        return $this;
    }

    /**
     * 设置超时时长，单位为秒, 可以使用小数, 0 为不限制, 默认120秒
     * @param float $timeout
     * @return $this
     */
    public function timeout(float $timeout)
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * 设置尝试次数, 当因为网络问题导致的请求失败, 会重试; 默认为 0, 即不重试
     * @param int $tryTime
     * @return $this
     */
    public function tryTime(int $tryTime)
    {
        $this->tryTime = max(0, $tryTime);
        return $this;
    }

    /**
     * 待爬取对象的字符编码，设置为 null 则自动获取 (gbk, utf..默认 null)
     * @param ?string $charset
     * @return $this
     */
    public function charset(?string $charset)
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * 开启压缩传输, 会发送 Accept-Encoding 请求报文, 收到响应后会自动解码，这可以减少数据传输时间，但会略微增加服务器 CPU 消耗。
     * 如果是通过 header 函数手动设置 Accept-Encoding 首部，该设置将被忽略，收到响应不会自动解码。默认为 false
     * @param bool $useEncoding
     * @return $this
     */
    public function useEncoding(bool $useEncoding = true)
    {
        $this->useEncoding = $useEncoding;
        return $this;
    }

    /**
     * HTTP authorization 身份验证信息，如果手动设置 Authorization header, 该设置将被忽略。
     * 也可以设置为 null 取消已设置身份验证信息
     * @param ?string $name 用户
     * @param ?string $pass 密码
     * @param ?int $type 验证方法，默认为 AUTH_BASIC
     * @return $this
     */
    public function auth(?string $name, string $pass = null, int $type = null)
    {
        $this->auth = $name ? [$name, $pass, $type] : null;
        return $this;
    }

    /**
     * 若重定向导致请求域名发生变化, 是否仍然发送 authorization header, 默认为 false
     * @param bool $alwaysAuth
     * @return $this
     */
    public function alwaysAuth(bool $alwaysAuth = true)
    {
        $this->alwaysAuth = $alwaysAuth;
        return $this;
    }

    /**
     * 自动跟随 Location 重定向，默认为 true
     * @param bool $autoRedirect
     * @return $this
     */
    public function autoRedirect(bool $autoRedirect = true)
    {
        $this->autoRedirect = $autoRedirect;
        return $this;
    }

    /**
     * 允许请求对象的跳转次数，默认为 5 次
     * @param int $maxRedirect
     * @return $this
     */
    public function maxRedirect(int $maxRedirect)
    {
        $this->maxRedirect = max(0, $maxRedirect);
        return $this;
    }

    /**
     * 重定向时, 自动使用上一个连接作为 Header referer，默认为 true
     * @param bool $autoReferrer
     * @return $this
     */
    public function autoReferrer(bool $autoReferrer = true)
    {
        $this->autoReferrer = $autoReferrer;
        return $this;
    }

    /**
     * 重定向时, 自动使用前面的响应返回 set-cookie 头作为请求 cookie，默认为 true.
     * 若同时设置了 request cookie header, 二者将同时作为请求 cookie 发送。
     * 关闭之后，将仅发送手动设置的 cooke header
     * @param bool $autoCookie
     * @return $this
     */
    public function autoCookie(bool $autoCookie = true)
    {
        $this->autoCookie = $autoCookie;
        return $this;
    }

    /**
     * 只允许使用 IPV4 请求
     * @return $this
     */
    public function forceIpV4()
    {
        $this->forceIp = true;
        return $this;
    }

    /**
     * 只允许使用 IPV6 请求
     * @return $this
     */
    public function forceIpV6()
    {
        $this->forceIp = false;
        return $this;
    }

    /**
     * 允许使用 IPV4 或 IPV6 请求
     * @return $this
     */
    public function allowIpV46()
    {
        $this->forceIp = null;
        return $this;
    }

    /**
     * 是否接受异常返回(etc: 403,404...)，默认为 false
     * @param bool $allowError
     * @return $this
     */
    public function allowError(bool $allowError = true)
    {
        $this->allowError = $allowError;
        return $this;
    }

    /**
     * 是否开启 debug，默认 false.
     * @param resource|string|bool $debug 可设置 resource 或 文件路径作为 debug 的输出目标，也可设置为 true 使用临时 stream.
     *                                    若不开启 debug, 则设置为 false(默认)
     * @return $this
     */
    public function debug($debug = true)
    {
        if (!is_bool($debug) && !is_string($debug) && !is_resource($debug)) {
            throw new RequestException('Argument 1 must be resource, string or bool');
        }
        $this->debug = $debug;
        return $this;
    }

    /**
     * https 请求是否校验对方证书合法性，默认为 true.
     * - 校验 SSL 是否合法需要用到 CA 证书，默认会使用 openssl 库或系统提供的证书，
     *   若失败则降级使用组件包内缺省提供了一个本地 CA 证书，但该证书可能不是最新版。
     * - 可以通过该函数设置一个 [本地路径] 作为降级后使用证书，若设置了路径，代表开启验证。
     * - 路径可以是一个证书文件地址，如 .pem 或 .crt 文件 (可在 https://curl.se/docs/caextract.html 下载证书)
     * - 路径还可以是一个文件夹地址，会自动索引文件夹下所有 .pem 和 .crt 证书
     * @param string|bool $verify 是否开启验证，或指定为一个 CA 证书文件路径，或一个包含多个 CA 的文件夹路径
     * @param bool $verifyHost 开启验证的情况下，是否验证证书与域名匹配，默认为 true
     * @return $this
     */
    public function sslVerify($verify = true, bool $verifyHost = true)
    {
        if (!is_bool($verify) && !is_string($verify)) {
            throw new RequestException('Argument 1 must be bool or string');
        }
        $this->sslVerify = $verify;
        $this->sslVerifyHost = $verifyHost;
        return $this;
    }

    /**
     * 设置双向认证的 SSL 证书（仅支持 PEM 格式），也可以设置 null 取消已设置证书。
     * > 证书设置有以下几种形式：
     * - 证书已包含密钥，且不需要密码： sslCert($cert)
     * - 证书已包含密钥，需要密码： sslCert($cert, null, $passwd)
     * - 证书和密钥为两个文件，且不需要密码： sslCert($cert, $key)
     * - 证书和密钥为两个文件，需要密码： sslCert($cert, $key, $passwd)
     * @param ?string $cert 公钥路径
     * @param ?string $key 私钥路径
     * @param ?string $passwd 公钥密码
     * @return $this
     */
    public function sslCert(?string $cert, ?string $key = null, string $passwd = null)
    {
        $this->sslCert = $cert ? [$cert, $key, $passwd] : null;
        return $this;
    }

    /**
     * 是否获取 SSL 证书信息，可以在 response info 中获取，默认为 false
     * @param bool $sslCaptureInfo
     * @return $this
     */
    public function sslCaptureInfo(bool $sslCaptureInfo = true)
    {
        $this->sslCaptureInfo = $sslCaptureInfo;
        return $this;
    }

    /**
     * 设置 http 请求代理, 也可以设置 null 取消已设置代理
     * @param ?string $proxy 代理地址，需包含协议和端口，如 http://127.0.0.1:1080 或 socks5://127.0.1.1:8118
     * @param ?string $user 可选:用户
     * @param ?string $pass 可选:密码
     * @param ?int $type 可选:验证方法，仅支持 AUTH_BASIC(默认), AUTH_NTLM
     * @return $this
     */
    public function proxy(?string $proxy, string $user = null, string $pass = null, int $type = null)
    {
        $this->proxy = $proxy ? [$proxy, $user, $pass, $type] : null;
        return $this;
    }

    /**
     * 在未设置 proxy 的情况下，是否使用系统代理
     * 1. null: cli 模式下使用, cgi 模式下不使用 (默认)
     * 2. true: 任何模式下都使用
     * 3. false: 任何模式下都不使用
     * @param ?bool $use
     * @return $this
     */
    public function useSystemProxy(?bool $use = true)
    {
        $this->useSystemProxy = null === $use ? null : $use;
        return $this;
    }

    /**
     * 若代理服务器为 https 协议, 手动设置校验代理服务器的 CA，参考 sslVerify 说明。
     * > 使用 curl 扩展 且 php >= 7.3.0 有效
     * @param string|bool $verify 是否验证代理服务器证书，或指定为验证代理服务器的 CA 路径，默认为 false
     * @param bool $verifyHost 开启验证的情况下，是否验证证书与域名匹配，一般代理服务器使用 ip，默认为 false
     * @return $this
     * @see sslVerify
     */
    public function proxyVerify($verify = true, bool $verifyHost = false)
    {
        if (!is_bool($verify) && !is_string($verify)) {
            throw new RequestException('Argument 1 must be bool or string');
        }
        $this->proxyVerify = $verify;
        $this->proxyVerifyHost = $verifyHost;
        return $this;
    }

    /**
     * 若代理服务器需要双向认证, 设置本地证书，参考 sslCert 说明
     * > 使用 curl 扩展 且 php >= 7.3.0 有效
     * @param ?string $cert 公钥路径
     * @param ?string $key 私钥路径
     * @param ?string $passwd 公钥密码
     * @return $this
     * @see sslCert
     */
    public function proxyCert(?string $cert, ?string $key = null, string $passwd = null)
    {
        $this->proxyCert = $cert ? [$cert, $key, $passwd] : null;
        return $this;
    }

    /**
     * 设置发送给代理服务器的自定义 header, 格式为 ['Proxy-Foo: foo', 'Proxy-Bar: bar'].
     * 该 header 仅在代理服务器为 HTTP 服务器时有效，其他 (比如 socks5 代理) 无效
     * @param array $header
     * @return $this
     */
    public function proxyHeader(array $header)
    {
        $this->proxyHeader = $header;
        return $this;
    }

    /**
     * 自定义域名 host 对应的 ip，功能类似于本地 host 配置，这在测试时还是很有用的（若使用代理，该配置无效）。 如：
     * - ["domain.com:80:127.0.0.1", "example.com:443:192.168.1.2"]
     * @param array $hostResolver
     * @return $this
     */
    public function hostResolver(array $hostResolver)
    {
        $this->hostResolver = $hostResolver;
        return $this;
    }

    /**
     * 设置请求 method (GET, POST..)，默认根据是否有 request body 自动使用 GET 或 POST
     * @param ?string $method
     * @return $this
     */
    public function method(?string $method)
    {
        $this->method = $method ? strtoupper($method) : null;
        return $this;
    }

    /**
     * 设置使用的 Http 协议版本，支持 1 / 1.1 / 2 / 0, 默认为 0, 自动选择协议版本
     * @param float $version
     * @return $this
     */
    public function version(float $version)
    {
        $this->version = $version;
        return $this;
    }

    /**
     * 设置请求 url
     * @param ?string $url
     * @return $this
     */
    public function url(?string $url)
    {
        $urls = Helper::parseUrl($url);
        $this->url = $url;
        $this->scheme = $urls['scheme'];
        $this->host = $urls['host'];
        $this->port = $urls['port'];
        $this->user = $urls['user'];
        $this->pass = $urls['pass'];
        $this->urlPath = $urls['path'];
        $this->urlQuery = $urls['query'];
        return $this;
    }

    /**
     * 重置所有 header 请求数据
     * @param array $headers
     * @return $this
     */
    public function headers(array $headers)
    {
        $this->headers = static::formatHeaderArray($headers);
        return $this;
    }

    /**
     * 重置指定的 header 请求数据
     * @param array|string $key 可通过 array 同时设置多个
     * @param array|string|null $value 若 key 为 string, 指定 key 的值, 值为 null 会移除 key
     * @return $this
     */
    public function putHeader($key, $value = null)
    {
        if (is_array($key)) {
            $this->headers = static::formatHeaderArray($key, $this->headers);
        } elseif(is_string($key) && !empty($key)) {
            if (null === $value) {
                unset($this->headers[Helper::formatHeaderKey($key)]);
            } else {
                $this->headers[Helper::formatHeaderKey($key)] = $value;
            }
        } else {
            static::ArgumentMustBeArrayOrString();
        }
        return $this;
    }

    /**
     * 格式化 headers array
     * @param array $headers
     * @param array|null $current
     * @return array
     */
    private static function formatHeaderArray(array $headers, array $current = null)
    {
        $headers = array_combine(
            array_map([Helper::class, 'formatHeaderKey'], array_keys($headers)),
            array_values($headers)
        );
        if ($current) {
            $headers = array_merge($current, $headers);
        }
        return array_filter($headers, function ($value) {
            return null !== $value;
        });
    }

    /**
     * 获取指定的 header 请求数据
     * @param string $key
     * @param array|string|null $default
     * @return mixed
     */
    public function getHeader(string $key, $default = null)
    {
        $key = Helper::formatHeaderKey($key);
        return array_key_exists($key, $this->headers) ? $this->headers[$key] : $default;
    }

    /**
     * 删除指定的 header 请求数据
     * @param array|string $key 可使用 array 参数批量删除
     * @return $this
     */
    public function removeHeader($key)
    {
        if (is_array($key)) {
            $this->headers = array_diff_key(
                $this->headers, array_flip(array_map([Helper::class, 'formatHeaderKey'], $key))
            );
        } elseif (is_string($key)) {
            unset($this->headers[Helper::formatHeaderKey($key)]);
        } else {
            static::ArgumentMustBeArrayOrString();
        }
        return $this;
    }

    /**
     * 清空所有已设置的 header 请求数据
     * @return $this
     */
    public function clearHeader()
    {
        $this->headers = [];
        return $this;
    }

    /**
     * 设置 header 中的 user-agent 值, 值为 null 则移除 user-agent header
     * @param ?string $userAgent
     * @return $this
     */
    public function userAgent(?string $userAgent)
    {
        return $this->putHeader('User-Agent', $userAgent);
    }

    /**
     * 设置 header 中的 referer 值, 值为 null 则移除 referer header
     * @param ?string $referrer
     * @return $this
     */
    public function referrer(?string $referrer)
    {
        return $this->putHeader('Referer', $referrer);
    }

    /**
     * 设置 header 中的 cookie 值, 值为 null 则移除 cookie header
     * @param ?string $cookie
     * @return $this
     */
    public function cookie(?string $cookie)
    {
        return $this->putHeader('Cookie', $cookie);
    }

    /**
     * 设置 header Content-Type 为 multipart/$type
     * @param string $type
     * @return $this
     */
    public function forceMultipart(string $type = 'form-data')
    {
        return $this->putHeader('Content-Type', 'multipart/'.$type);
    }

    /**
     * 禁止发送 Expect 100-continue header, 某些服务端可能不支持
     * @return $this
     */
    public function disableExpect()
    {
        return $this->putHeader('Expect', '');
    }

    /**
     * 设置 header 使之模拟浏览器 ajax 请求
     * @return $this
     */
    public function asAjax()
    {
        return $this->putHeader('X-Requested-With', 'XMLHttpRequest');
    }

    /**
     * 重置所有 query 请求数据, 该 query 会追加到 url 中, url 中的 query 仍然有效.
     * @param array|string $queries 可使用数组 [foo => foo, bar => bar] 或 字符串(如 "foo=foo&bar=bar")
     * @return $this
     */
    public function queries($queries)
    {
        if (!is_array($queries)) {
            if (is_string($queries)) {
                parse_str($queries, $queries);
            } else {
                static::ArgumentMustBeArrayOrString();
            }
        }
        $this->queries = $queries;
        return $this;
    }

    /**
     * 重置指定 query 请求数据
     * @param array|string $key 可通过 array 同时新增(重置)多个
     * @param array|string|null $value 若 key 为 string, 指定 key 的值, value 值为 null 将被移除
     * @return $this
     */
    public function putQuery($key, $value = null)
    {
        if (is_array($key)) {
            $this->queries = array_filter(array_merge($this->queries, $key), function ($value) {
                return null !== $value;
            });
        } elseif(is_string($key) && !empty($key)) {
            if (null === $value) {
                unset($this->queries[$key]);
            } else {
                $this->queries[$key] = $value;
            }
        } else {
            static::ArgumentMustBeArrayOrString();
        }
        return $this;
    }

    /**
     * 通过字符串（如 "foo=foo&bar=bar"）新增(重置) query 请求数据
     * @param string $query
     * @return $this
     */
    public function putRawQuery(string $query)
    {
        parse_str($query, $query);
        return $this->putQuery($query);
    }

    /**
     * 获取指定的 query 请求数据
     * @param string $key
     * @param array|string|null $default
     * @return mixed
     */
    public function getQuery(string $key, $default = null)
    {
        return array_key_exists($key, $this->queries) ? $this->queries[$key] : $default;
    }

    /**
     * 移除指定的 query 请求数据
     * @param array|string $key 可使用 array 参数批量删除
     * @return $this
     */
    public function removeQuery($key)
    {
        if (is_array($key)) {
            $this->queries = array_diff_key($this->queries, array_flip($key));
        } elseif (is_string($key)) {
            unset($this->queries[$key]);
        } else {
            static::ArgumentMustBeArrayOrString();
        }
        return $this;
    }

    /**
     * 清空所有已设置的 query 请求数据
     * @return $this
     */
    public function clearQuery()
    {
        $this->queries = [];
        return $this;
    }

    /**
     * 重置所有 param 请求数据，用于 POST 请求
     * @param array|string $params 可使用数组 [foo => foo, bar => bar] 或 字符串(如 "foo=foo&bar=bar")
     * @return $this
     */
    public function params($params)
    {
        if (!is_array($params)) {
            if (is_string($params)) {
                parse_str($params, $params);
            } else {
                static::ArgumentMustBeArrayOrString();
            }
        }
        $this->params = $params;
        return $this;
    }

    /**
     * 重置指定的 param 请求数据
     * @param array|string $key 可通过 array 同时新增(重置)多个
     * @param array|string|null $value 若 key 为 string, 指定 key 的值
     * @return $this
     */
    public function putParam($key, $value = null)
    {
        if (is_array($key)) {
            $this->params = array_filter(array_merge($this->params, $key), function ($value) {
                return null !== $value;
            });
        } elseif(is_string($key) && !empty($key)) {
            if (null === $value) {
                unset($this->params[$key]);
            } else {
                $this->params[$key] = $value;
            }
        } else {
            static::ArgumentMustBeArrayOrString();
        }
        return $this;
    }

    /**
     * 通过字符串（如 "foo=foo&bar=bar"）新增(重置) param 请求数据
     * @param string $param
     * @return $this
     */
    public function putRawParam(string $param)
    {
        parse_str($param, $param);
        return $this->putParam($param);
    }

    /**
     * 获取指定的 param 请求数据
     * @param string $key
     * @param array|string|null $default
     * @return mixed
     */
    public function getParam(string $key, $default = null)
    {
        return array_key_exists($key, $this->params) ? $this->params[$key] : $default;
    }

    /**
     * 移除指定的 param 请求数据
     * @param array|string $key 可使用 array 参数批量删除
     * @return $this
     */
    public function removeParam($key)
    {
        if (is_array($key)) {
            $this->params = array_diff_key($this->params, array_flip($key));
        } elseif (is_string($key)) {
            unset($this->params[$key]);
        } else {
            static::ArgumentMustBeArrayOrString();
        }
        return $this;
    }

    /**
     * 清空所有已设置的 param 请求数据
     * @return $this
     */
    public function clearParam()
    {
        $this->params = [];
        return $this;
    }

    /**
     * 重置所有 files 请求数据，可以与 params 一起使用 multipart/form-data 方式发送 POST 请求。
     *
     * HTTP 上传文件需要文件名、MimeType, 默认情况下会自动获取，若获取不准，可通过 filename, mimetype 手动设置，
     * 对于常见文件格式，仅设置 filename(需包含后缀) 即会自动设置 mimetype, $files 参数格式为：
     *
     *      $files = [
     *          key => file
     *          key => [file => , filename => , mimetype => ,]
     *      ];
     *
     * 其中 file 指定要添加文件，支持以下几种变量类型：
     *
     *      - 文件路径: (string) filepath;
     *      - 文件指针: (resource) $stream;
     *      - 文件内容: (array) [$content];
     *
     * 如果某个 key 字段需要多个文件，可以使用如下格式：
     *
     *      $files = [
     *          key[0] => file
     *          key[1] => file
     *      ];
     * @param array $files
     * @return $this
     */
    public function files(array $files)
    {
        return $this->addFile($files, true);
    }

    /**
     * 重置指定 file 请求数据
     * @param array|string $key 可通过 array 同时新增(重置)多个, array 格式与 files() 方法参数一致
     * @param array|resource|string|null $file 参数值与 files() 方法中 file 一致, 支持文件 路径/指针/内容; 值为 null 会移除 $key
     * @param ?string $filename 手动设置文件名
     * @param ?string $mimetype 手动设置文件 MimeType，常见文件格式设置文件名即会自动设置 mimetype
     * @return $this
     * @see files
     */
    public function putFile($key, $file = null, string $filename = null, string $mimetype = null)
    {
        if (is_array($key)) {
            return $this->addFile($key);
        }
        if(is_string($key) && !empty($key)) {
            if (null === $file) {
                unset($this->files[$key]);
            } else {
                if ($filename || $mimetype) {
                    $file = compact('file', 'filename', 'mimetype');
                }
                $this->files[$key] = $file;
            }
        } else {
            static::ArgumentMustBeArrayOrString();
        }
        return $this;
    }

    /**
     * 校验并批量设置 files 数据
     * @param array $files
     * @param bool $reset
     * @return $this
     */
    protected function addFile(array $files, bool $reset = false)
    {
        if ($reset) {
            $this->files = [];
        }
        foreach ($files as $key => $value) {
            if (null === $value) {
                if (!$reset) {
                    unset($this->files[$key]);
                }
                continue;
            }
            $file = is_array($value) && isset($value['file']) ? $value['file'] : $value;
            if (is_string($file) || (is_array($file) && is_string(current($file))) || is_resource($file)) {
                $this->files[$key] = $value;
            } else {
                throw new RequestException('File must be string or resource');
            }
        }
        return $this;
    }

    /**
     * 获取指定的 file 设定值
     * @param string $key
     * @param null $default
     * @return array
     */
    public function getFile(string $key, $default = null)
    {
        return array_key_exists($key, $this->files) ? $this->files[$key] : $default;
    }

    /**
     * 移除指定的 file 参数
     * @param array|string $key 可使用 array 参数批量删除
     * @return $this
     */
    public function removeFile($key)
    {
        if (is_array($key)) {
            $this->files = array_diff_key($this->files, array_flip($key));
        } elseif (is_string($key)) {
            unset($this->files[$key]);
        } else {
            static::ArgumentMustBeArrayOrString();
        }
        return $this;
    }

    /**
     * 清除所有已设置 file 数据
     * @return $this
     */
    public function clearFile()
    {
        $this->files = [];
        return $this;
    }

    /**
     * 参数必须为 array 或 string
     */
    private static function ArgumentMustBeArrayOrString()
    {
        throw new RequestException('Argument 1 must be array or string');
    }

    /**
     * 设置 request body, $body 可使用如下类型：
     * - string
     * - StreamInterface 对象
     * - array (以 json 形式发送)
     * - 通过 "file://path" 指定的文件路径，将发送文件内容
     * - null, 移除已设置 body
     * @param StreamInterface|array|resource|string|null $body
     * @param ?string $mimeType 设置 request body 的 Content-Type, 若已通过 header 设置, 该设置将被忽略
     * @return $this
     */
    public function body($body, string $mimeType = null)
    {
        $this->body = $body;
        $this->mimeType = $mimeType;
        return $this;
    }

    /**
     * 设置保存路径， 默认会写入到 php://temp
     * - 可以设置为文件路径
     * - 也可以设置一个可写的文件指针
     * - 若文件或指针内已有内容，会被清空
     * - 设置为 null 移除已经设置的 save path
     * @param resource|string|null $path
     * @return $this
     */
    public function saveTo($path)
    {
        if (null !== $path && !is_string($path) && !is_resource($path)) {
            throw new RequestException('Save path must be string, resource or null');
        }
        $this->saveTo = $path;
        return $this;
    }

    /**
     * 若保存到文件路径，是否自动创建保存文件夹，默认为 true
     * @param bool $auto
     * @return $this
     */
    public function makeDirAuto(bool $auto = true)
    {
        $this->makeDirAuto = $auto;
        return $this;
    }

    /**
     * 设置收到回复的回调函数, 对方刚输出 HTTP/version code 头时触发，
     * 该回调可能会多次触发，比如对方先响应 301 跳转，再响应 200。
     * - callback(int $code, float $version, Request $request)
     *
     * 特性：
     * - 可在回调中使用 $request->stop() 终止请求
     * - 可在回调中重置 $request 参数, 并返回 true 发起新的请求
     * @param ?callable $onResponse
     * @return $this
     */
    public function onResponse(?callable $onResponse)
    {
        $this->onResponse = $onResponse;
        return $this;
    }

    /**
     * 设置接收到 response header 时的回调（每个 header 头都触发回调, 并不是接收完所有 Header 后回调），
     * 如同 onResponse, 该回调可能触发多组，比如对方先响应 301 跳转，再响应 200，那么每次响应的 header 都会触发该函数。
     * - callback(string $key, string $value, Request $request)
     *
     * 特性：
     * - 可在回调中使用 $request->stop() 终止请求
     * - 可在回调中重置 $request 参数, 并返回 true 发起新的请求
     * @param ?callable $onHeaderLine
     * @return $this
     */
    public function onHeaderLine(?callable $onHeaderLine)
    {
        $this->onHeaderLine = $onHeaderLine;
        return $this;
    }

    /**
     * 接收完所有 header 后的回调
     * - callback(array $headers, int $code, Request $request)
     *
     * 特性：
     * - 可在回调中使用 $request->stop() 终止请求
     * - 可在回调中重置 $request 参数, 并返回 true 发起新的请求
     * @param ?callable $onHeader
     * @return $this
     */
    public function onHeader(?callable $onHeader)
    {
        $this->onHeader = $onHeader;
        return $this;
    }

    /**
     * 发生重定向时的回调
     * - callback(string $location, string $url, Request $request)
     *
     * 特性：
     * - 可在回调中使用 $request->stop() 终止请求
     * - 可在回调中重置 $request 参数, 并返回 true 发起新的请求
     * - 此时可以使用 $request->response 获取当前响应的 code/headers
     * @param ?callable $onRedirect
     * @return $this
     */
    public function onRedirect(?callable $onRedirect)
    {
        $this->onRedirect = $onRedirect;
        return $this;
    }

    /**
     * 设置上传 request body 时的进度监听函数
     * - callback(int $uploaded, int $total, Request $request)
     *
     * 特性：
     * - 可在回调中使用 $request->stop() 终止请求
     * - 该回调可能多次发生, 比如服务端返对于 POST 或 PUT 请求返回 307 响应, request body 将多次上传
     * - 回调参数 $total 可能为 0, 比如使用 chunked 方式上传 body
     * @param ?callable $onUpload
     * @return $this
     */
    public function onUpload(?callable $onUpload)
    {
        $this->onUpload = $onUpload;
        return $this;
    }

    /**
     * 设置下载 response body 时的进度监听函数
     * - callback(int $downloaded, int $total, Request $request)
     *
     * 特性：
     * - 可在回调中使用 $request->stop() 终止请求
     * - 若服务端响应为重定向, 将不会下载正文, 仅在最终页面才会该函数
     * - 回调参数 $total 可能为 0, 服务端可能使用 chunked 方式传输正文
     * @param ?callable $onDownload
     * @return $this
     */
    public function onDownload(?callable $onDownload)
    {
        $this->onDownload = $onDownload;
        return $this;
    }

    /**
     * 设置发生异常，请求已终止时的回调函数:
     * - callback(Exception $e, Request $request)
     * @param ?callable $onError
     * @return $this
     */
    public function onError(?callable $onError)
    {
        $this->onError = $onError;
        return $this;
    }

    /**
     * 设置请求成功后的回调函数:
     * - callback(Response $response, Request $request)
     *
     * 特性：
     * - 可在回调中重置 $request 参数, 并返回 true 发起新的请求
     * > 某些时候需要通过前一个请求的结果，才能访问下一个请求，可以在 onComplete 中直接发出下一个请求，
     * 若前后请求地址的 host 相同，这样可以直接利用已打开的连接通道。
     * @param ?callable $onComplete
     * @return $this
     */
    public function onComplete(?callable $onComplete)
    {
        $this->onComplete = $onComplete;
        return $this;
    }

    /**
     * 取消(中断)请求, 可以在回调函数中使用
     */
    public function stop()
    {
        throw new UserCanceledException('User canceled request');
    }

    /**
     * 发送请求
     * @param TransportInterface|string|null $driver
     * @return Response
     */
    public function send($driver = null)
    {
        return Curl::driver($driver)->fetch($this);
    }

    /**
     * 重置响应 Request 的 Response 对象
     * @param ?Response $response
     * @return $this
     */
    public function setResponse(?Response $response)
    {
        $this->response = $response;
        return $this;
    }

    /**
     * @param $name
     * @return bool
     */
    public function __isset($name)
    {
        return property_exists($this, $name);
    }

    /**
     * @param $name
     * @return mixed
     */
    public function __get($name)
    {
        if (property_exists($this, $name)) {
            return $this->{$name};
        }
        throw new RequestException('Undefined property: '.__CLASS__.'::$'.$name);
    }
}
