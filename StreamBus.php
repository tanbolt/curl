<?php
namespace Tanbolt\Curl;

use Tanbolt\Curl\Exception\RequestException;

/**
 * Class StreamBus: 将多个 StreamInterface 对象 或 字符串转为 StreamInterface 对象
 * @package Tanbolt\Curl
 */
class StreamBus implements StreamInterface
{
    /**
     * @var int
     */
    protected $size;

    /**
     * @var StreamInterface[]
     */
    protected $streams = [];

    /**
     * @var int
     */
    private $current = 0;

    /**
     * @var int
     */
    private $pos = 0;

    /**
     * 添加一个 Curl\StreamInterface 对象, 有两点需要注意
     * - 若在添加 StreamInterface 前, 指针已有偏移, 添加后会自动将指针设置到开始位置
     * - 在添加后切勿对 StreamInterface 进行读写、定位等操作, 否则会导致内外不一致
     * @param StreamInterface $stream
     * @return $this
     */
    public function addStream(StreamInterface $stream)
    {
        if (!$stream->isReadable() || !$stream->isSeekable()) {
            throw new RequestException('Stream must be readable and seekable');
        }
        $stream->rewind();
        $this->size = null;
        $this->streams[] = $stream;
        return $this;
    }

    /**
     * 添加一个 string stream 对象
     * @param string $str
     * @return $this
     */
    public function addString(string $str)
    {
        return $this->addStream(static::createStringStream($str));
    }

    /**
     * 获取当前设置的数据集合
     * @return StreamInterface[]
     */
    public function streams()
    {
        return $this->streams;
    }

    /**
     * 将标量 $resource 转为 Stream
     * @param mixed $resource
     * @return ?Stream
     */
    protected static function createStringStream($resource)
    {
        if (!is_scalar($resource)) {
            throw new RequestException('Invalid resource type: ' . gettype($resource));
        }
        $fp = fopen('php://temp', 'r+b');
        fwrite($fp, strval($resource));
        return new Stream($fp, 'r', true);
    }

    /**
     * @inheritdoc
     */
    public function isReadable()
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function isWritable()
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function isSeekable()
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function content(bool $rewind = true)
    {
        if ($rewind) {
            $this->rewind();
        }
        $buffer = '';
        while (!$this->eof()) {
            $buffer .= $this->read(65536);
        }
        return $buffer;
    }

    /**
     * @inheritdoc
     */
    public function size()
    {
        if ($this->size !== null) {
            return $this->size;
        }
        $size = 0;
        foreach ($this->streams() as $stream) {
            if (null === $s = $stream->size()) {
                return null;
            }
            $size += $s;
        }
        return $this->size = $size;
    }

    /**
     * @inheritdoc
     */
    public function eof()
    {
        $max = count($streams = $this->streams()) - 1;
        if ($max < 0 || $this->current > $max) {
            return true;
        }
        return $max === $this->current && $streams[$max]->eof();
    }

    /**
     * @inheritdoc
     */
    public function rewind()
    {
        return $this->seek(0);
    }

    /**
     * @inheritdoc
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        $fromEnd = SEEK_END === $whence;
        $seek = $fromEnd ? -1 * $offset : (SEEK_CUR === $whence ? $this->pos + $offset : $offset);
        if ($seek < 0) {
            throw new RequestException('Seek offset length exceeds file size');
        }
        $count = count($streams = $this->streams());
        if (!$count) {
            throw new RequestException('Sub streams is empty');
        }

        // 查找 seek 命中的 stream 下标, 最终
        // 在 $current 之前的应该  seek到结尾(SEEK_SET/SEEK_CUR) 或 seek到开头(SEEK_END)
        // $current 应该 seek 到 $offsetSize
        // 在 $current 之后的应该  seek到开头(SEEK_SET/SEEK_CUR) 或 seek到结尾(SEEK_END)
        if ($fromEnd) {
            $streams = array_reverse($streams);
        }
        $size = 0;
        $total = 0;
        $current = null;
        $offsetSize = null;
        foreach ($streams as $k => $stream) {
            if (null === $size = $stream->size()) {
                throw new RequestException('Stream seek failed because get sub stream size failed');
            }
            $plus = $total + $size;
            if ($plus <= $seek) {
                $total = $plus;
            } else {
                $current = $k;
                // 注意 seek 在第一个 stream 就命中的情况
                $offsetSize = $fromEnd ? $plus - $seek : ($seek < $total ? $seek : $seek - $total);
                break;
            }
        }

        // seek 之后的 pos 位置
        $newPos = $fromEnd ? null : $seek;

        // 若循环完毕都没命中, 且总 size($total) 与 $seek 相等, 说明是刚好 seek 到开头(SEEK_END) 或 结尾(SEEK_SET/SEEK_CUR)
        if (null === $current && $total === $seek) {
            $current = $count - 1;
            $offsetSize = $fromEnd ? 0 : $size;
            if ($fromEnd) {
                $newPos = 0;
            }
        }

        // seek 到 开头之前(SEEK_END) 或 结尾之后(SEEK_SET/SEEK_CUR)
        if (null === $current || null === $offsetSize) {
            throw new RequestException('Seek offset length exceeds file size');
        }

        // 如果 $newPos 为 null, 说明是 SEEK_END 且不是刚好 seek 到开头, 读取前面 stream 的总 size 计算 $newPos
        if (null === $newPos) {
            $newPos = 0;
            for ($k = $current + 1; $k < $count; $k++) {
                if (null === $size = $streams[$k]->size()) {
                    throw new RequestException('Stream seek failed because get sub stream size failed');
                }
                $newPos += $size;
            }
            $newPos += $offsetSize;
        }

        // 最后一步, 对每一个 stream 执行 seek 操作
        foreach ($streams as $k => $stream) {
            if ($k < $current) {
                $stream->seek(0, $fromEnd ? SEEK_SET : SEEK_END);
            } elseif ($k === $current) {
                $stream->seek($offsetSize);
            } else {
                $stream->seek(0, $fromEnd ? SEEK_END : SEEK_SET);
            }
        }
        $this->pos = (int) $newPos;
        $this->current = $fromEnd ? $count - $current - 1 : $current;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function tell()
    {
        return $this->pos;
    }

    /**
     * @inheritdoc
     */
    public function read($length)
    {
        $max = count($streams = $this->streams()) - 1;
        if ($max < 0 || $this->current > $max) {
            return '';
        }
        $buffer = '';
        while (true) {
            $buffer .= $streams[$this->current]->read($length);
            $length -= strlen($buffer);
            if ($length <= 0 || ++$this->current > $max) {
                break;
            }
        }
        $this->pos += strlen($buffer);
        return $buffer;
    }

    /**
     * @inheritdoc
     */
    public function write($string)
    {
        throw new RequestException('Cannot write to an StreamBus');
    }

    /**
     * @inheritdoc
     */
    public function close()
    {
        foreach ($this->streams() as $stream) {
            $stream->close();
        }
        $this->size = null;
        $this->streams = [];
        $this->current = $this->pos = 0;
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
        $this->close();
    }
}
