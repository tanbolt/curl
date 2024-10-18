<?php
namespace Tanbolt\Curl;

use Tanbolt\Curl\Exception\RequestException;

/**
 * Class Stream: 将文件路径 或 resource 转为 StreamInterface
 * @package Tanbolt\Curl
 */
class Stream implements StreamInterface
{
    /**
     * @var string
     */
    private $file;

    /**
     * @var resource
     */
    private $stream;

    /**
     * @var bool
     */
    private $autoClose;

    /**
     * @var bool
     */
    private $seekable;

    /**
     * @var bool
     */
    private $readable;

    /**
     * @var bool
     */
    private $writable;

    /**
     * @var array
     */
    private $meta;

    /**
     * @var int
     */
    private $size;

    /**
     * Stream constructor.
     * @param resource|string $stream 文件路径 或 已打开的文件指针
     * @param string $mode 若是文件路径，可设置读写模式
     * @param bool $autoClose 若 $stream 参数指定为 resource, 是否在 Stream 对象销毁时关闭指针
     */
    public function __construct($stream, string $mode = 'rb', bool $autoClose = false)
    {
        if (is_string($stream)) {
            $this->stream = @fopen($stream, $mode);
            // 若 $stream 指定的路径为文件夹, php 也有可能返回 true, 所以这里双重判断
            if (!$this->stream || is_dir($stream)) {
                throw new RequestException('['.$stream.'] is not file.');
            }
            $this->file = $stream;
            $this->autoClose = true;
        } else {
            if (!is_resource($stream)) {
                throw new RequestException('Stream must be resource');
            }
            $this->stream = $stream;
            $this->autoClose = $autoClose;
        }
        $this->meta = $meta = stream_get_meta_data($this->stream);
        $this->seekable = (bool) $meta['seekable'];

        // 读写权限判断
        $mode = $meta['mode'];
        if (false !== strpos($mode, '+')) {
            $this->readable = $this->writable = true;
        } else {
            $i = 0;
            $len = strlen($mode);
            $this->readable = $this->writable = false;
            while ($i < $len) {
                $chr = $mode[$i++];
                if ('r' === $chr) {
                    $this->readable = true;
                    break;
                }
                if (in_array($chr, ['w', 'a', 'x', 'c'])) {
                    $this->writable = true;
                    break;
                }
            }
        }
    }

    /**
     * Stream 原始值，可能是 resource 或 文件路径
     * @return resource|string
     */
    public function original()
    {
        return $this->file ?: $this->stream;
    }

    /**
     * Stream resource 指针
     * @return resource
     */
    public function resource()
    {
        return $this->stream;
    }

    /**
     * 获取 指定/全部 的 stream meta
     * @param ?string $key
     * @param mixed $default
     * @return array|string|null
     */
    public function meta(string $key = null, $default = null)
    {
        if ($key) {
            return array_key_exists($key, $this->meta) ? $this->meta[$key] : $default;
        }
        return $this->meta;
    }

    /**
     * @inheritdoc
     */
    public function isReadable()
    {
        return $this->readable;
    }

    /**
     * @inheritdoc
     */
    public function isWritable()
    {
        return $this->writable;
    }

    /**
     * @inheritdoc
     */
    public function isSeekable()
    {
        return $this->seekable;
    }

    /**
     * @inheritdoc
     */
    public function size()
    {
        if (!$this->stream) {
            throw new RequestException('Stream resource has closed');
        }
        if (null !== $this->size) {
            return $this->size;
        }
        $position = ftell($this->stream);
        rewind($this->stream);
        $stats = fstat($this->stream);
        if ($position) {
            fseek($this->stream, $position);
        }
        if (isset($stats['size'])) {
            return $this->size = $stats['size'];
        }
        return null;
    }

    /**
     * @inheritdoc
     */
    public function content(bool $rewind = true)
    {
        if ($rewind) {
            $this->rewind();
        }
        $contents = stream_get_contents($this->stream);
        return false === $contents ? null : $contents;
    }

    /**
     * @inheritdoc
     */
    public function rewind()
    {
        if (!$this->stream) {
            throw new RequestException('Stream resource has closed');
        }
        if(!rewind($this->stream)) {
            throw new RequestException('Rewind stream resource failed');
        }
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function seek(int $offset, int $whence = SEEK_SET)
    {
        if (!$this->stream) {
            throw new RequestException('Stream resource has closed');
        }
        // 若 fseek 定位错误, 会对后续 read tell 产生影响, 尝试提前判断
        $error = SEEK_SET === $whence ? $offset < 0 : (SEEK_END === $whence && $offset > 0);
        if (!$error && null !== $size = $this->size()) {
            switch ($whence) {
                case SEEK_CUR:
                    if (false !== ($pos = ftell($this->stream)) && $pos + $offset > $size) {
                        $error = true;
                    }
                    break;
                case SEEK_SET:
                    $error = $offset > $size;
                    break;
                case SEEK_END:
                    $error = $size + $offset < 0;
                    break;
            }
        }
        if ($error || 0 !== fseek($this->stream, $offset, $whence)) {
            throw new RequestException('Seek offset length exceeds file size');
        }
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function tell()
    {
        if (!$this->stream) {
            throw new RequestException('Stream resource has closed');
        }
        if(false === $pos = ftell($this->stream)) {
            throw new RequestException('Get stream resource offset failed');
        }
        return $pos;
    }

    /**
     * @inheritdoc
     */
    public function eof()
    {
        return !$this->stream || feof($this->stream);
    }

    /**
     * @inheritdoc
     */
    public function read(int $length)
    {
        if (!$this->stream) {
            throw new RequestException('Stream resource has closed');
        }
        if (false === $data = fread($this->stream, $length)) {
            throw new RequestException('Unable read from stream');
        }
        return $data;
    }

    /**
     * @inheritdoc
     */
    public function write(string $string)
    {
        if (!$this->stream) {
            throw new RequestException('Stream resource has closed');
        }
        if (false === $length = fwrite($this->stream, $string)) {
            throw new RequestException('Unable write to stream');
        }
        $this->size = null;
        return $length;
    }

    /**
     * 将文件截断到给定的长度
     * @param int $size
     * @return $this
     */
    public function truncate(int $size)
    {
        if (!$this->stream) {
            throw new RequestException('Stream resource has closed');
        }
        if (!ftruncate($this->stream, $size)) {
            throw new RequestException('Truncate stream failed');
        }
        $this->size = null;
        return $this;
    }

    /**
     * @return $this
     */
    public function close()
    {
        if ($this->stream) {
            @fclose($this->stream);
            $this->stream = $this->size = null;
            $this->readable = $this->writable = $this->seekable = false;
        }
        return $this;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->content();
    }

    /**
     * destruct
     */
    public function __destruct()
    {
        if ($this->autoClose) {
            $this->close();
        }
    }
}
