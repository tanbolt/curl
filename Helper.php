<?php
namespace Tanbolt\Curl;

use Throwable;
use Tanbolt\Curl\Exception\RequestException;

class Helper
{
    /**
     * 循环读写 buffer 长度
     */
    const CHUNK_SIZE = 32768;

    /**
     * 同时最多可打开连接数
     */
    const MAX_OPEN_SOCKET = 50;

    /**
     * @var string
     */
    private static $caPath;

    /**
     * @var array
     */
    private static $mimeTypes = [
        'image/png' => 'png',
        'image/x-png' => 'png',
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/pjpeg' => ['jpg', 'jpeg'],
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'image/bmp' => 'bmp',
        'application/json' => 'json',
        'application/xml' => 'xml',
        'text/css' => 'css',
        'text/html' => ['html','htm','shtml'],
        'application/javascript' => ['js', 'javascript'],
        'application/rss+xml' => 'rss',
        'application/atom+xml' => 'atom',
        'application/rdf+xml' => 'rdf',
        'text/plain' => ['txt','xsl'],
    ];

    /**
     * 根据文件后缀返回其 mineType (如: png -> image/png)
     * @param ?string $extension
     * @return ?string
     */
    public static function getMimeType(?string $extension)
    {
        $extension = $extension ? strtolower(trim($extension)) : null;
        if (!empty($extension)) {
            foreach (static::$mimeTypes as $mimeType => $type) {
                if ($type === $extension || (is_array($type) && in_array($extension, $type))) {
                    return $mimeType;
                }
            }
        }
        return null;
    }

    /**
     * 根据 mineType 返回 文件后缀 (如: image/png -> png)
     * @param ?string $mimetype
     * @return ?string
     */
    public static function getExtension(?string $mimetype)
    {
        $mimetype = $mimetype ? strtolower(trim($mimetype)) : null;
        if (!empty($mimetype) && ($type = static::$mimeTypes[$mimetype] ?? null)) {
            return is_array($type) ? $type[0] : $type;
        }
        return null;
    }

    /**
     * $haystack 是否以 $needle 开始
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    public static function strStartsWith(string $haystack, string $needle)
    {
        return 0 === substr_compare($haystack, $needle, 0, strlen($needle));
    }

    /**
     * $haystack 是否以 $needle 结尾
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    public static function strEndsWith(string $haystack, string $needle)
    {
        return 0 === substr_compare($haystack, $needle, -strlen($needle));
    }

    /**
     * 格式化 header key
     * @param string $key
     * @return string
     */
    public static function formatHeaderKey(string $key)
    {
        return implode('-', array_map('ucfirst', explode('-', strtolower($key))));
    }

    /**
     * 解析一个 url string
     * @param ?string $url
     * @return array
     */
    public static function parseUrl(?string $url)
    {
        $urls = ['url' => $url];
        $parsed = empty($url) ? [] : @parse_url($url);
        $keys = ['scheme', 'host', 'port', 'user', 'pass', 'path', 'query'];
        foreach ($keys as $k => $key) {
            $urls[$key] = empty($parsed[$key]) ? null : ($k < 2 ? strtolower($parsed[$key]) : $parsed[$key]);
        }
        $urls['scheme'] = $urls['scheme'] ?: 'http';
        $urls['secure'] = 'https' === $urls['scheme'];
        $urls['port'] = empty($urls['port']) ? ($urls['secure'] ? 443 : 80) : (int) $urls['port'];
        $urls['path'] = '/' . (!empty($urls['path']) ? preg_replace('~/+~', '/', ltrim($urls['path'], '/')) : '');
        return $urls;
    }

    /**
     * 获取重定向 url 的绝对路径
     * @param string $redirect
     * @param string $scheme
     * @param string $host
     * @param string $port
     * @param string $path
     * @return string
     */
    public static function getAbsoluteUrl(string $redirect, string $scheme, string $host, string $port, string $path)
    {
        $redirect = trim($redirect);
        if (substr($redirect, 0, 2) === '//') {
            return $scheme.':'.$redirect;
        }
        if (preg_match('#^(https?)://#', $redirect)) {
            return $redirect;
        }
        $httpHost = $scheme.'://'.$host.($port ? ':'.$port : '');
        if (empty($redirect)) {
            return $httpHost.$path;
        }
        if (0 < $pos = strpos($redirect, '#')) {
            $redirect = substr($redirect, 0, $pos);
        }
        // 首字符为 "/" , httpHost 下的绝对路径
        if ('/' === $redirect[0]) {
            return $httpHost.$redirect;
        }
        // 首字符为 ".", path 的相对路径
        if ('.' === $redirect[0]) {
            if (strlen($redirect) < 2) {
                return $httpHost.$path;
            }
            if ($redirect[1] == '/') {
                return $httpHost.substr($redirect, 1, strlen($redirect) - 2);
            }
            $i = 0;
            $relative = '';
            $pathStep = 0;
            $urls = explode('/', $redirect);
            foreach ($urls as $u) {
                if ($u == '..') {
                    $pathStep++;
                } elseif ($i < count($urls) - 1) {
                    $relative .= $urls[$i] . '/';
                } else {
                    $relative .= $urls[$i];
                }
                $i++;
            }
            $urls = explode('/', $path);
            if (count($urls) <= $pathStep) {
                return $httpHost . '/' . $relative;
            }
            $absolute = '';
            for ($i = 0; $i < count($urls) - $pathStep; $i++) {
                $absolute .= $urls[$i]. '/';
            }
            return $httpHost . $absolute . $relative;
        }
        // path 的下层路径
        return $httpHost . rtrim($path, '/') . '/' . $redirect;
    }

    /**
     * 获取 $request 代理设置
     * @param Request $request
     * @return array|null
     */
    public static function getRequestProxy(Request $request)
    {
        $proxy = $user = $pass = $auth = null;
        if (!empty($request->proxy)) {
            list($proxy, $user, $pass, $auth) = array_pad($request->proxy, 4, null);
        }
        if (empty($proxy)) {
            $proxy = Helper::getSystemProxy('https' === $request->scheme, $request->useSystemProxy);
        }
        if (empty($proxy)) {
            return null;
        }
        $parsed = @parse_url($proxy);
        $user = $user ?: ($parsed['user'] ?? null);
        $pass = $pass ?: ($parsed['pass'] ?? null);
        $scheme = strtolower($parsed['scheme']) ?? null;
        $host = strtolower($parsed['host']) ?? null;
        $port = $parsed['port'] ?? null;
        $proxy = ($scheme ?: 'http').'://'.$host.($port ? ':'.$port : '');
        return compact('proxy', 'scheme', 'host', 'port', 'user', 'pass', 'auth');
    }

    /**
     * 获取系统代理
     * @param false $https
     * @param ?bool $useSystemProxy
     * @return array|string|null
     */
    public static function getSystemProxy(bool $https = false, bool $useSystemProxy = null)
    {
        if (false === $useSystemProxy || (null === $useSystemProxy && 'cli' != php_sapi_name()) ) {
            return null;
        }
        if ($https && ( ($proxy = getenv('https_proxy')) || ($proxy = getenv('HTTPS_PROXY')) ) ) {
            return $proxy;
        }
        if (($proxy = getenv('http_proxy')) || ($proxy = getenv('HTTP_PROXY'))) {
            return $proxy;
        }
        return null;
    }

    /**
     * 获取 $request 要发送的 Request body
     * @param Request $request
     * @param array $header
     * @return StreamInterface|string|null
     */
    public static function getRequestBody(Request $request, array &$header = [])
    {
        // request 直接指定了 body
        if ($request->body) {
            $mimetype = $request->mimeType;
            if ($request->body instanceof StreamInterface) {
                $body = $request->body;
            } elseif (is_array($request->body)) {
                $body = json_encode($request->body);
                $mimetype = $mimetype ?: 'application/json';
            } elseif (is_resource($request->body)) {
                $body = new Stream($request->body);
            } else {
                $body = (string) $request->body;
                $body = 'file://' === substr($body, 0, 7) ? new Stream(substr($body, 7)) : $body;
            }
            if (!isset($header['Content-Type'])) {
                if (!$mimetype && $body instanceof Stream) {
                    $mimetype = static::getMimeType(pathinfo($body->meta('uri'), PATHINFO_EXTENSION));
                }
                $header['Content-Type'] = $mimetype ?: 'application/octet-stream';
            }
            return $body;
        }
        // 包含上传文件 或 强制使用 multipart
        if (!empty($request->files) || 'multipart/form-data' === $request->getHeader('Content-Type')) {
            if ($body = static::getStreamFormBody($request)) {
                $header['Content-Type'] = 'multipart/form-data; boundary='.$body->boundary();
                return $body;
            }
        }
        // 使用 post fields 方式
        if (!empty($request->params)) {
            $header['Content-Type'] = 'application/x-www-form-urlencoded';
            return http_build_query($request->params);
        }
        return null;
    }

    /**
     * 从 Request 对象中提取待发送的 post body
     * @param Request $request
     * @return StreamForm
     */
    public static function getStreamFormBody(Request $request)
    {
        $stream = new StreamForm();
        $count = 0;
        if (!empty($request->params)) {
            foreach ($request->params as $key => $value) {
                if (empty($key)) {
                    continue;
                }
                $stream->addParam($key, $value);
                $count++;
            }
        }
        if (!empty($request->files)) {
            foreach ($request->files as $key => $upload) {
                if (empty($key)) {
                    continue;
                }
                if (is_array($upload) && isset($upload['file'])) {
                    $file = $upload['file'];
                    unset($upload['file']);
                    $extra = $upload;
                } else {
                    $extra = null;
                    $file = $upload;
                }
                if (is_array($file)) {
                    $stream->addFileContent($key, current($file), $extra);
                } else {
                    $stream->addFile($key, $file, $extra);
                }
                $count++;
            }
        }
        if (!$count) {
            return null;
        }
        return $stream;
    }

    /**
     * 设置并获取 response 要写入的 body stream
     * @param Request $request
     * @param bool $force
     * @return Stream
     */
    public static function setResponseBody(Request $request, bool $force = false)
    {
        if($body = $request->response->body) {
            // 不强制创建新 body -> 重复使用
            if (!$force) {
                $body->truncate(0);
                return $body;
            }
            // 当前 body 为空文件, 直接删除
            if(is_string($path = $body->original()) && !$body->size()) {
                $body->close();
                @unlink($path);
            }
        }
        // 创建新的 response body
        if (!$request->saveTo) {
            $body = new Stream(fopen('php://temp', 'w+b'), 'r', true);
        } else {
            $saveTo = $request->saveTo;
            if (is_string($saveTo)) {
                $dir = dirname($saveTo);
                if (!is_dir($dir)) {
                    if (!$request->makeDirAuto) {
                        throw new RequestException('Save directory of "'.$saveTo.'" not exist');
                    } elseif (!mkdir($dir, 0755, true)) {
                        throw new RequestException('Create save directory of "'.$saveTo.'" failed');
                    }
                }
                $body = new Stream($saveTo, 'w+b');
            } else {
                $body = new Stream($saveTo);
                $body->truncate(0);
            }
            if (!$body->isWritable()) {
                throw new RequestException('Cannot write to a non-writable stream');
            }
        }
        $request->response->__setResponseValue('body', $body);
        return $body;
    }

    /**
     * 设置 request->response 异常
     * @param Request $request
     * @param Throwable $exception
     * @param bool $callbackError
     */
    public static function setResponseException(Request $request, Throwable $exception, bool $callbackError = false)
    {
        $response = $request->response;
        $response->__setResponseValue('error', $exception);
        if ($callbackError) {
            $response->__setResponseValue('isCallbackError', true);
        }
        // 发生请求异常导致中断, 若 saveTo 是文件文件路径且为空, 删除
        // (不为空就不删了, 比如在 onDownload 中 stop(), 保留已下载还是有意义的)
        if (($body = $response->body) && is_string($path = $body->original()) && !$body->size()) {
            $body->close();
            @unlink($path);
            $response->__setResponseValue('body', null);
        }
        if ($request->onError) {
            try {
                call_user_func($request->onError, $exception, $request);
            } catch (Throwable $e) {
                // do nothing
            }
        }
    }

    /**
     * 将 response header set-cookie 字符串转为数组并缓存
     * @param string $setCookie
     * @param string $domain
     * @param array $cookies
     * @return array
     */
    public static function setResponseCookie(string $setCookie, string $domain, array &$cookies = [])
    {
        $cake = [];
        $items = explode(';', $setCookie);
        foreach ($items as $item) {
            $kv = explode('=', $item, 2);
            $len = count($kv);
            $key = strtolower(ltrim($kv[0]));
            if (!isset($cake['name'])) {
                if (2 !== $len) {
                    $cake = null;
                    break;
                }
                $cake['name'] = $key;
                $cake['value'] = rtrim($kv[1]);
                continue;
            }
            if (2 !== $len) {
                // 浏览器对于 http 请求设置 secure 的 cookie 会忽略, libcurl 未忽略, 这里与 libcurl 保持一致
                if ('secure' === $key) {
                    $cake[$key] = true;
                }
                continue;
            }
            $value = rtrim($kv[1]);
            // 域名不匹配, 直接忽略
            if ('domain' === $key) {
                if ('.' === $value[0]) {
                    $value = substr($value, 1);
                }
                if ($domain !== $value && !static::strEndsWith($value, '.'.$domain)) {
                    $cake = null;
                    break;
                }
                $cake[$key] = $value;
                continue;
            }
            // 其他所需 key
            if (in_array($key, ['path', 'expires', 'max-age'])) {
                $cake[$key] = $value;
            }
        }
        if (!$cake) {
            return null;
        }
        $now = time();
        $expires = 0;
        if (isset($cake['max-age'])) {
            if ($maxAge = (int) $cake['max-age']) {
                $expires = $now + $maxAge;
            }
            unset($cake['max-age']);
        }
        if (!$expires && isset($cake['expires'])) {
            $expires = strtotime($cake['expires']);
        }
        $cake['expires'] = $expires;
        if (!isset($cake['secure'])) {
            $cake['secure'] = false;
        }
        if (!isset($cake['path'])) {
            $cake['path'] = '/';
        }
        if (isset($cake['domain'])) {
            $cake['sub'] = true;
        } else {
            $cake['domain'] = $domain;
            $cake['sub'] = false;
        }
        // 更新
        $deleted = false;
        if ($expires && $expires < $now) {
            foreach ($cookies as $k => $cookie) {
                if ($cookie['name'] === $cake['name'] && $cookie['path'] === $cake['path'] &&
                    $cookie['domain'] === $cake['domain'] && $cookie['sub'] === $cake['sub']
                ) {
                    $deleted = true;
                    unset($cookies[$k]);
                }
            }
            $cookies = array_values($cookies);
        }
        if (!$deleted) {
            $cookies[] = $cake;
        }
        return $cake;
    }

    /**
     * 从 response set-cookie 缓存中提取 request cookie
     * @param array $cookies
     * @param string $host
     * @param string $path
     * @param bool $secure
     * @return string
     */
    public static function getRequestCookie(array $cookies,string $host, string $path, bool $secure = false)
    {
        $now = time();
        $index = 0;
        $cakes = $sorts = $paths = [];
        foreach ($cookies as $cookie) {
            if (!$secure && $cookie['secure']) {
                continue;
            }
            if ($cookie['expires'] && $cookie['expires'] < $now) {
                continue;
            }
            if (!static::strStartsWith($path, $cookie['path'])) {
                continue;
            }
            $domain = $cookie['domain'];
            if ($domain !== $host && (!$cookie['sub'] || !static::strEndsWith($host, '.'.$domain))) {
                continue;
            }
            $sorts[] = $index;
            $paths[] = $cookie['path'];
            $cakes[] = [$cookie['name'], $cookie['value']];
            $index++;
        }
        // 同名 cookie 可能会多次设置, 比如 secure sub path 值不同导致, secure 可较为方便的过滤
        // 针对 sub 值不同, 按照设置顺序，后面的覆盖前面的，对于 path 值不同，父级覆盖子级, 如 设置在 / 的优先级高于设置在 /test 路径下的
        array_multisort($paths, SORT_DESC, $sorts, SORT_ASC, $cakes);
        $cookie = [];
        foreach ($cakes as $cake) {
            $name = $cake[0];
            $cookie[$name] = $name.'='.$cake[1];
        }
        return join('; ', $cookie);
    }

    /**
     * 转 string 为 utf-8 编码
     * @param string $string
     * @param ?string $charset
     * @return string
     */
    public static function convertToUtf8(string $string, string $charset = null)
    {
        if ($charset) {
            if (function_exists('mb_convert_encoding')) {
                return mb_convert_encoding($string, 'UTF-8', $charset);
            }
            if (function_exists('iconv')) {
                return iconv($charset, 'UTF-8//IGNORE', $string);
            }
        }
        return $string;
    }

    /**
     * 根据 401 response 获取 request auth header
     * @param Stream $body
     * @param string $method
     * @param string $uri
     * @param string $wwwAuth
     * @param string $user
     * @param string $pass
     * @return bool|string
     */
    public static function getRequestAuth(
        Stream $body, string $method, string $uri, string $wwwAuth, string $user, string $pass
    ) {
        $type = strstr($wwwAuth, ' ', true);
        $type = strtolower(false === $type ? $wwwAuth : $type);
        if ('basic' === $type) {
            return Helper::getBasicAuth($user, $pass);
        }
        if ('digest' !== $type) {
            return false;
        }
        $digest = [];
        //'@(\w+)=(?:(?:")([^"]+)"|([^\s,$]+))@'
        if (preg_match_all('@(\w+)=(?:"([^"]+)"|([^\s,$]+))@', $wwwAuth, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $digest[strtolower($match[1])] = $match[2];
            }
        }
        return static::getDigestAuth($body, $method, $uri, $user, $pass, $digest);
    }

    /**
     * 获取 Basic auth header
     * @param string $user
     * @param ?string $pass
     * @return string
     */
    public static function getBasicAuth(string $user, ?string $pass)
    {
        return 'Basic '.base64_encode($user . ':' . $pass);
    }

    /**
     * 获取 Digest auth header
     * @param Stream $body
     * @param string $method
     * @param string $uri
     * @param string $user
     * @param string $pass
     * @param array $digest
     * @return bool|string
     */
    public static function getDigestAuth(
        Stream $body, string $method, string $uri, string $user, string $pass, array $digest = []
    ) {
        if (!isset($digest['realm']) || !isset($digest['qop']) || !isset($digest['nonce']) || !isset($digest['opaque'])) {
            return false;
        }
        $qop = $digest['qop'];
        $auth = [];
        $auth['username'] = $user;
        $auth['realm'] = $digest['realm'];
        $auth['nonce'] = $digest['nonce'];
        $auth['uri'] = $uri;
        $auth['qop'] = $qop;
        $auth['nc'] = 1;
        $auth['cnonce'] = uniqid();

        $authInt = 'auth-int' === $qop ? true : ('auth' === $qop ? false : null);
        $content =  $authInt ? ':'.$body->content() : '';
        $ha1 = md5($user . ':' . $digest['realm'] . ':' . $pass . $content);
        $ha2 = md5($method . ':' . $uri . $content);

        // RFC7616 仅允许 qop 为 auth 或 auth-int, 但旧标准 RFC2617 允许其他值, 这里兼容一下
        // https://tools.ietf.org/html/rfc7616
        // https://tools.ietf.org/html/rfc2617#section-3.2.2.1
        if (null === $authInt) {
            $auth['response'] = md5($ha1 . ':' . $digest['nonce'] . ':' . $ha2);
        } else {
            $auth['response'] = md5(
                $ha1 . ':' . $auth['nonce'] . ':' . $auth['nc']. ':' .
                $auth['cnonce']. ':' . $auth['qop'] . ':' . $ha2
            );
        }
        $auth['opaque'] = $digest['opaque'];
        $digestAuth = '';
        foreach ($auth as $key => $val) {
            $digestAuth .= ' '.$key.'='.'"'.$val.'"';
        }
        return 'Digest'.$digestAuth;
    }

    /**
     * 获取当前可用的 ssl 证书
     * @return string
     */
    public static function getCaPath()
    {
        if (self::$caPath) {
            return self::$caPath;
        }
        if (DIRECTORY_SEPARATOR == '/') {
            // https://github.com/curl/curl/blob/0d16a49c16a868524a3e51d390b5ea106ce9b51c/acinclude.m4#L2151
            // https://stackoverflow.com/questions/24675167/ca-certificates-mac-os-x
            // https://cloud.google.com/appengine/docs/standard/python/sockets/ssl_support
            $findPath = [
                '/etc/pki/tls/certs/ca-bundle.crt', //Red Hat, CentOS, Fedora, Mandriva
                '/etc/ssl/certs/ca-certificates.crt', //Ubuntu, Debian
                '/usr/local/share/certs/ca-root-nss.crt', //FreeBSD old(er) Redhat
                '/usr/local/etc/openssl/cert.pem', //OS X provided by homebrew
                '/usr/share/ssl/certs/ca-bundle.crt', //old(er) Redhat
                '/etc/ca-certificates.crt', //Google app engine
            ];
            foreach ($findPath as $path) {
                if (file_exists($path)) {
                    return self::$caPath = $path;
                }
            }
        }
        return self::$caPath = __DIR__ . DIRECTORY_SEPARATOR . 'cert.pem';
    }
}
