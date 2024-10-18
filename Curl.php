<?php
namespace Tanbolt\Curl;

use Throwable;
use Tanbolt\Curl\Transport\Socket;
use Tanbolt\Curl\Transport\LibCurl;
use Tanbolt\Curl\Exception\RequestException;

class Curl
{
    /**
     * 默认通信驱动，不设置则使用 curl > socket, 可设置为
     * - curl: 强制使用 libcurl
     * - socket: 强制使用 socket
     * - TransportInterfaceClass: 实现了接口的类名
     * - Object(TransportInterface): 已实例化的驱动实例对象
     *
     * 注意区别:
     * - 使用字符串形式指定的，每次调用都会初始化一个新对象，使用完之后自动销毁对象
     * - 直接使用实例对象，每次调用都共享该对象，注意在合适的时候销毁对象，特别是 CLI 运行模式下
     * @var TransportInterface|string
     */
    public static $defaultDriver;

    /**
     * @var bool
     */
    private static $libcurlAble;

    /**
     * @var array
     */
    private $collection = [];

    /**
     * 获取爬虫的驱动器: curl 或 socket，如参数为空，则根据 php 是否开启 curl 扩展返回驱动器
     * @param TransportInterface|string|null $driver
     * @return TransportInterface
     */
    public static function driver($driver = null)
    {
        if (empty($driver)) {
            if (empty(static::$defaultDriver)) {
                if (null === self::$libcurlAble) {
                    self::$libcurlAble = function_exists('curl_exec');
                }
                return self::$libcurlAble ? new LibCurl() : new Socket();
            }
            $driver = static::$defaultDriver;
        }
        if ($driver instanceof TransportInterface) {
            return $driver;
        }
        if ('curl' === $driver) {
            return new LibCurl();
        }
        if ('socket' === $driver) {
            return new Socket();
        }
        if (!is_string($driver) || !is_subclass_of($driver, TransportInterface::class)) {
            throw new RequestException('Curl driver must be subclass of TransportInterface.');
        }
        return new $driver();
    }

    /**
     * 创建 Request 对象
     * @param string|null $url
     * @param string|array|null $methodOrOptions
     * @return Request
     */
    public static function request(string $url = null, $methodOrOptions = null)
    {
        return new Request($url, $methodOrOptions);
    }

    /**
     * 添加一个或多个 Request 对象
     * @param Request ...$request
     * @return $this
     */
    public function add(...$request)
    {
        $this->collection = array_merge($this->collection, static::flattenRequest($request));
        return $this;
    }

    /**
     * 移除一个或多个 Request 对象
     * @param Request|Request[] ...$request
     * @return $this
     */
    public function remove(...$request)
    {
        $this->collection = array_diff_key($this->collection, array_flip(array_keys(static::flattenRequest($request))));
        return $this;
    }

    /**
     * 将 Requests 转为一维数组
     * @param array $array
     * @return array
     */
    protected static function flattenRequest(array $array)
    {
        $requests = [];
        array_walk_recursive($array, function ($item) use (&$requests) {
            if (!$item instanceof Request) {
                throw new RequestException('Argument must be Request instance');
            }
            $key = spl_object_hash($item);
            if (!isset($requests[$key])) {
                $requests[$key] = $item;
            }
        });
        return $requests;
    }

    /**
     * 清除所有已设置 Request 对象
     * @return $this
     */
    public function clear()
    {
        $this->collection = [];
        return $this;
    }

    /**
     * 获取所有已设置 Request 对象
     * @return Request[]
     */
    public function collection()
    {
        return $this->collection;
    }

    /**
     * 请求所有已设置 Request 对象
     * @param int $maxConnection 同时发出请求的最大连接数
     * @param TransportInterface|string|null $driver 强制使用指定的驱动, 默认自动
     * @return int
     * @throws Throwable
     */
    public function send(int $maxConnection = 0, $driver = null)
    {
        return static::driver($driver)->fetchMulti($this->collection, $maxConnection);
    }
}
