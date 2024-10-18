<?php
namespace Tanbolt\Curl;

/**
 * Interface StreamInterface
 * @package Tanbolt\Curl
 *
 * 任何实现该接口的对象都可以作为 Curl Request 的 body, 也可以作为 Response body 的写入对象
 *
 * 作为 Request body, 必须 Readable
 *    - rewind(): 必须实现该接口, 有些情况 stream 需要多次使用
 *    - size(): 若返回 null, 会使用 Transfer-Encoding 发送请求
 *    - content(): 若 size 较小且可以直接返回 string, 请求会直接发送该 string
 *
 * 作为 Response body, 必须 Writable
 *
 */
interface StreamInterface
{
    /**
     * 当前 stream 是否可读
     * @return bool
     */
    public function isReadable();

    /**
     * 当前 stream 是否可写
     * @return bool
     */
    public function isWritable();

    /**
     * 当前 stream 是否可定位
     * @return bool
     */
    public function isSeekable();

    /**
     * 返回 stream 大小, 若无法获取, 返回 null
     * @return ?int
     */
    public function size();

    /**
     * 转为 string, 若文件较大或其他原因无法转换的, 返回 null.
     * @param bool $rewind 是否定位到开头读取所有内容, 默认为 true; 否则由当前 seek 位置开始读取, 获取后指针会移至文件尾部
     * @return ?string
     */
    public function content(bool $rewind = true);

    /**
     * 定位到 stream 开头, 失败抛出异常
     * @return static
     */
    public function rewind();

    /**
     * 定位指针到指定的位置, 参见 fseek, 失败抛出异常
     * @param int $offset
     * @param int $whence
     * @return static
     * @see https://www.php.net/manual/zh/function.fseek.php
     */
    public function seek(int $offset, int $whence = SEEK_SET);

    /**
     * 返回当前指针位置, 失败抛出异常
     * @return int
     */
    public function tell();

    /**
     * 判断指针是否已到结尾, 返回 true 或 false
     * @return bool
     */
    public function eof();

    /**
     * 从当前指针位置读取 $length 字节数据, 成功返回数据, 失败抛出异常
     * @param int $length
     * @return string
     */
    public function read(int $length);

    /**
     * 写入 $string 到 stream, 成功返回写入字节数, 失败抛出异常
     * @param string $string
     * @return int
     */
    public function write(string $string);

    /**
     * 关闭 stream, 返回关闭是否成功
     * @return bool
     */
    public function close();
}
