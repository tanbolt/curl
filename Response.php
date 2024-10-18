<?php
namespace Tanbolt\Curl;

use Throwable;
use Tanbolt\Curl\Exception\RequestException;

/**
 * Class Response
 * @package Tanbolt\Curl
 * @property-read ?Throwable $error 请求失败的异常信息, 无异常返回 null; 异常信息也可能发生在保存文件或结束回调时, 此时仍可以获得请求结果
 * @property-read bool $isCallbackError 是否为回调函数 (如 onHeader, onDownload 等) 产生的异常
 * @property-read int $tryTime 因为网络问题而重新尝试的次数
 * @property-read ?string $url 最终请求的 url
 * @property-read ?float $version 响应 HTTP 版本
 * @property-read ?int $code 响应 HTTP 状态码
 * @property-read ?string $message 响应 HTTP 状态消息
 * @property-read array $headers 所有响应 header, key 为首字母大写格式, value 可能是 字符串 或 数组(如 Set-Cookie)
 * @property-read ?string $charset 响应内容的字符编码
 * @property-read ?string $contentType 响应内容的类型
 * @property-read array $redirects 重定向历史
 * @property-read ?Stream $body 响应内容 Stream
 * @property-read ?int $length 响应内容的长度
 * @property-read array $info 请求过程的相关信息
 * @property-read ?Stream $debug 请求过程的 debug 信息 Stream
 */
class Response
{
    /**
     * 请求失败的异常信息
     * @var Throwable
     */
    private $_error;

    /**
     * 是否为调用 onHeader onResponse 回调函数时产生的异常
     * @var bool
     */
    private $_isCallbackError = false;

    /**
     * 获取响应总共尝试请求的次数
     * @var int
     */
    private $_tryTime = 0;

    /**
     * 跳转历史, 是一个二维数组, 每在回调函数中发起一次新请求, 就会多一个子数组
     * [
     *      [],
     *      []
     * ]
     * @var array
     */
    private $_redirects = [];

    /**
     * http version
     * @var float
     */
    private $_version;

    /**
     * http status code
     * @var int
     */
    private $_code;

    /**
     * http status text
     * @var string
     */
    private $_message;

    /**
     * 最终请求 url
     * @var string
     */
    private $_url;

    /**
     * http header
     * @var array
     */
    private $_headers = [];

    /**
     * 内容编码
     * @var string
     */
    private $_charset;

    /**
     * 内容类型
     * @var string
     */
    private $_contentType;

    /**
     * 内容 stream
     * @var Stream
     */
    private $_body;

    /**
     * 请求信息
     * @var array
     */
    private $_info = [];

    /**
     * debug 信息
     * @var Stream
     */
    private $_debug;

    /**
     * 内容 string
     * @var string
     */
    private $content;

    /**
     * 响应 hash 缓存, 判断是否可能陷入死循环
     * @var array
     */
    private $resHash = [];

    /**
     * 获取指定的 header 值
     * @param string $key
     * @param mixed $default
     * @return array|string|null
     */
    public function header(string $key, $default = null)
    {
        $key = Helper::formatHeaderKey($key);
        return array_key_exists($key, $this->_headers) ? $this->_headers[$key] : $default;
    }

    /**
     * 获取指定的 header 的第一个值
     * @param string $key
     * @param mixed $default
     * @return array|mixed|string|null
     */
    public function headerFirst(string $key, $default = null)
    {
        $value = $this->header($key, $default);
        return is_array($value) ? $value[0] : $value;
    }

    /**
     * 获取 header 字符串
     * @return string
     */
    public function headerString()
    {
        if (!$this->_version) {
            return '';
        }
        $string = sprintf('HTTP/%s %s %s', $this->_version, $this->_code, $this->_message)."\r\n";
        foreach ($this->_headers as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $string .= sprintf("%s: %s\r\n", $key, $v);
                }
            } else {
                $string .= sprintf("%s: %s\r\n", $key, $value);
            }
        }
        return $string;
    }

    /**
     * response 内容 string
     * @return ?string
     */
    public function content()
    {
        if (!$this->_body) {
            return null;
        }
        if (null !== $this->content) {
            return $this->content ?: $this->_body->content();
        }
        if ($this->_contentType && $this->_charset !== 'UTF-8' && stripos($this->_contentType, 'text/') !== false) {
            if (!$this->content) {
                $this->content = Helper::convertToUtf8($this->_body->content(), $this->_charset);
            }
            return $this->content;
        }
        $this->content = false;
        return $this->_body->content();
    }

    /**
     * response body json
     * @return ?array
     */
    public function json()
    {
        $content = $this->content();
        return $content ? json_decode($content, true) : null;
    }

    /**
     * 获取指定的请求信息
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function info(string $key, $default = null)
    {
        return array_key_exists($key, $this->_info) ? $this->_info[$key] : $default;
    }

    /**
     * 重置一个 response 值 (etc: header, body, info, code...)
     * 主要在驱动器接口中使用, 当然外部也可以使用, 但需要知道该函数可能带来的副作用
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function __setResponseValue(string $key, $value)
    {
        if ('start' === $key) {
            $this->_headers = $this->_info = [];
            $this->_version = $this->_code = $this->_message = $this->_contentType = null;
            return $this;
        }
        if ('end' === $key) {
            $this->resHash = [];
            // 请求结束后, 如果仅有一个子数组, 说明未手动发起新请求, 直接使用这唯一的子数组作为 跳转记录
            if (1 === count($this->redirects)) {
                $this->redirects = $this->redirects[0];
            }
            if ($this->_body) {
                $this->_body->rewind();
            }
            return $this;
        }
        switch ($key) {
            case 'info':
                $count = ($count = count($this->_redirects)) ? max(0, count($this->_redirects[$count - 1]) - 1) : 0;
                $value['redirect_count'] = $count;
                break;
            case 'charset':
                $value = $value ? strtoupper($value) : null;
                break;
            case 'body':
                $this->content = null;
                break;
        }
        $this->{'_'.$key} = $value;
        return $this;
    }

    /**
     * 设置一行 response header, 主要在驱动器接口中使用
     * - true: 结束
     * - [code, version]: response
     * - [key => value]: headerLine
     * - null: 出错
     * @param string $header
     * @return array|true|null
     */
    public function __putHeaderLine(string $header)
    {
        $header = trim($header);
        // end
        if (empty($header)) {
            return true;
        }
        // response
        if (strpos($header, 'HTTP/') === 0) {
            $this->_headers = [];
            $this->_contentType = null;
            list($version, $code, $message) = array_pad(explode(' ', $header, 3), 3, null);
            $this->_version = (float) substr($version, 5);
            $this->_code = (int) $code;
            $this->_message = (string) $message;
            return [$this->_code, $this->_version];
        }
        $key = $value = null;
        if (false !== $pos = strpos($header, ':')) {
            $key = substr($header, 0, $pos);
            $value = trim(substr($header, $pos + 1));
        }
        // error
        if (!$key) {
            return null;
        }
        // header
        $key = Helper::formatHeaderKey($key);
        if (!isset($this->_headers[$key])) {
            $this->_headers[$key] = $value;
        } elseif (is_array($this->_headers[$key])) {
            $this->_headers[$key][] = $value;
        } else {
            $this->_headers[$key] = [$this->_headers[$key], $value];
        }
        if ('Content-Type' === $key) {
            if (empty($this->_contentType)) {
                $this->_contentType = explode(';', $value, 2)[0];
            }
            if (empty($this->_charset) && preg_match('/charset=([A-Za-z0-9-_]+)/i', $value, $match)) {
                $this->_charset = $match[1] ? strtoupper($match[1]) : null;
            }
        }
        return [$key => $value];
    }

    /**
     * 检测是否可能陷入死循环
     * @return bool
     */
    public function __mayLoop()
    {
        if (!$this->url) {
            return false;
        }
        $hash = md5($this->url.$this->_version.$this->_code.$this->_message);
        $times = $this->resHash[$hash] ?? 0;
        if ($times > 1) {
            $this->resHash = [];
            return true;
        }
        if ($times > 0) {
            $this->resHash[$hash]++;
        } else {
            $this->resHash[$hash] = 1;
        }
        return false;
    }

    /**
     * @param $name
     * @return bool
     */
    public function __isset($name)
    {
        return 'length' === $name || property_exists($this, '_'.$name);
    }

    /**
     * @param $name
     * @return mixed
     */
    public function __get($name)
    {
        if ('length' === $name) {
            return $this->_body ? $this->_body->size() : null;
        }
        $key = '_'.$name;
        if (property_exists($this, $key)) {
            return $this->{$key};
        }
        throw new RequestException('Undefined property: '.__CLASS__.'::$'.$name);
    }

    /**
     * @return null|string
     */
    public function __toString()
    {
        if (!$this->_version) {
            return '';
        }
        return $this->headerString() ."\r\n" . $this->content();
    }
}
