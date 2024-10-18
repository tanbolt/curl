<?php

use Tanbolt\Curl\Stream;
use PHPUnit\Framework\TestCase;
use Tanbolt\Curl\StreamInterface;
use Tanbolt\Curl\Exception\RequestException;

class CurlStreamTest extends TestCase
{
    public function testConstruct()
    {
        try {
            new Stream(__DIR__);
            static::fail('It should throw exception when stream file not exist');
        } catch (RequestException $e) {
            static::assertTrue(true);
        }

        // file
        $filepath = __DIR__.'/Fixtures/stream.txt';
        $stream = new Stream($filepath);
        static::assertTrue($stream->isSeekable());
        static::assertTrue($stream->isReadable());
        static::assertFalse($stream->isWritable());
        static::assertEquals($filepath, $stream->original());
        static::assertTrue(is_resource($stream->resource()));
        static::assertEquals($filepath, $stream->meta('uri'));

        $stream = new Stream($filepath, 'r+');
        static::assertTrue($stream->isReadable());
        static::assertTrue($stream->isWritable());

        $stream = new Stream($filepath, 'a');
        static::assertFalse($stream->isReadable());
        static::assertTrue($stream->isWritable());

        // resource
        $fp = fopen($filepath, 'rb');
        $stream = new Stream($fp);
        static::assertTrue($stream->isSeekable());
        static::assertTrue($stream->isReadable());
        static::assertFalse($stream->isWritable());
        static::assertSame($fp, $stream->original());
        static::assertTrue(is_resource($stream->resource()));
        static::assertEquals($filepath, $stream->meta('uri'));

        $stream = new Stream($fp, 'r+');
        static::assertTrue($stream->isReadable());
        static::assertFalse($stream->isWritable());

        $stream = new Stream(fopen($filepath, 'r+b'));
        static::assertTrue($stream->isReadable());
        static::assertTrue($stream->isWritable());

        $stream = new Stream(fopen($filepath, 'ab'));
        static::assertFalse($stream->isReadable());
        static::assertTrue($stream->isWritable());

        // autoClose
        $fp = fopen('php://memory', 'rb');
        $stream = new Stream($fp);
        unset($stream);
        self::assertTrue(is_resource($fp));

        $fp = fopen('php://memory', 'rb');
        $stream = new Stream($fp, 'r', true);
        unset($stream);
        self::assertFalse(is_resource($fp));
    }

    public function testReadStream()
    {
        $filepath = __DIR__.'/Fixtures/stream.txt';
        $filesize = filesize($filepath);
        $content = file_get_contents($filepath);

        $fp = fopen($filepath, 'r+b');
        fseek($fp, 10);

        $stream = new Stream($fp);
        static::assertEquals($filesize, $stream->size());
        static::assertEquals(substr($content, 10), $stream->content(false));
        static::assertEquals($content, $stream->content());

        // content
        $stream->rewind();
        static::assertEquals($content, $stream->content());
        static::assertEquals($filesize, $stream->tell());

        // 循环读取
        $buffer = '';
        $stream->rewind();
        while (!$stream->eof()) {
            $buffer .= $stream->read(3096);
        }
        static::assertEquals($content, $buffer);

        // 超出范围的 seek
        self::checkExceedsSeek($stream, $filesize);

        // 正常范围内的 seek
        self::checkOffsetSeek($stream, $content);
    }

    public function testWriteStream()
    {
        $fp = fopen('php://memory', 'r+b');
        fwrite($fp, 'start');
        $stream = new Stream($fp);

        static::assertSame(3, $stream->write('foo'));
        static::assertEquals(8, $stream->tell());
        static::assertEquals(8, $stream->size());
        static::assertEquals('', $stream->read(3));

        static::assertSame(3, $stream->write('bar'));
        static::assertEquals(11, $stream->tell());
        static::assertEquals(11, $stream->size());
        static::assertEquals('', $stream->read(3));

        $stream->seek(0);
        static::assertEquals(0, $stream->tell());
        static::assertEquals(11, $stream->size());
        static::assertEquals('startf', $stream->read(6));

        $stream->seek(4);
        static::assertEquals('tfooba', $stream->read(6));

        static::assertSame(3, $stream->write('tan'));
        static::assertEquals(13, $stream->size());
        static::assertEquals('', $stream->content(false));
        static::assertEquals('startfoobatan', $stream->content());
    }

    public function testTruncateStream()
    {
        $fp = fopen('php://memory', 'r+b');
        fwrite($fp, 'start');
        $stream = new Stream($fp);

        static::assertSame($stream, $stream->truncate(2));
        static::assertEquals(2, $stream->size());
        $stream->rewind();
        static::assertEquals('st', $stream->read(10));
        static::assertEquals(2, $stream->tell());

        $stream->truncate(0)->rewind();
        static::assertEquals(0, $stream->size());
        static::assertEquals('', $stream->read(10));
        static::assertEquals(0, $stream->tell());
    }

    // 测试 seek 到 stream 边界之外
    public static function checkExceedsSeek(StreamInterface $stream, int $filesize)
    {
        $stream->seek(1);
        TestCase::assertEquals(1, $stream->tell());
        try {
            $stream->seek(-1);
            TestCase::fail('It should throw exception when seek offset length exceeds file size');
        } catch (RequestException $e) {
            TestCase::assertTrue(true);
        }
        try {
            $stream->seek($filesize + 1);
            TestCase::fail('It should throw exception when seek offset length exceeds file size');
        } catch (RequestException $e) {
            TestCase::assertTrue(true);
        }

        try {
            $stream->seek($filesize, SEEK_CUR);
            TestCase::fail('It should throw exception when seek offset length exceeds file size');
        } catch (RequestException $e) {
            TestCase::assertTrue(true);
        }
        try {
            $stream->seek(-2, SEEK_CUR);
            TestCase::fail('It should throw exception when seek offset length exceeds file size');
        } catch (RequestException $e) {
            TestCase::assertTrue(true);
        }

        try {
            $stream->seek(1, SEEK_END);
            TestCase::fail('It should throw exception when seek offset length exceeds file size');
        } catch (RequestException $e) {
            TestCase::assertTrue(true);
        }
        try {
            $stream->seek($filesize + 1, SEEK_END);
            TestCase::fail('It should throw exception when seek offset length exceeds file size');
        } catch (RequestException $e) {
            TestCase::assertTrue(true);
        }
    }

    // 测试正常 seek 后的读取
    public static function checkOffsetSeek(StreamInterface $stream, $content)
    {
        $filesize = strlen($content);
        $middle = floor($filesize / 2);

        // 测试 SEEK_SET:  定位到开头
        TestCase::assertSame($stream, $stream->seek(0));
        TestCase::assertEquals(0, $stream->tell());
        TestCase::assertEquals(substr($content, 0, 50), $stream->read(50));
        TestCase::assertEquals(min(50, $filesize), $stream->tell());

        TestCase::assertSame($stream, $stream->seek(1));
        TestCase::assertEquals(1, $stream->tell());
        TestCase::assertEquals(substr($content, 1, 50), $stream->read(50));
        TestCase::assertEquals(min(51, $filesize), $stream->tell());

        // 定位到中间
        TestCase::assertSame($stream, $stream->seek($middle));
        TestCase::assertEquals($middle, $stream->tell());
        TestCase::assertEquals(substr($content, $middle, 50), $stream->read(50));
        TestCase::assertEquals(min($middle + 50, $filesize), $stream->tell());

        // 定位到结尾
        TestCase::assertSame($stream, $stream->seek($filesize));
        TestCase::assertEquals($filesize, $stream->tell());
        TestCase::assertEquals('', $stream->read(50));
        TestCase::assertEquals($filesize, $stream->tell());

        TestCase::assertSame($stream, $stream->seek($filesize - 1));
        TestCase::assertEquals($filesize - 1, $stream->tell());
        TestCase::assertEquals(substr($content, $filesize - 1), $stream->read(5));
        TestCase::assertEquals($filesize, $stream->tell());

        // SEEK_CUR: 定位到中间
        TestCase::assertSame($stream, $stream->seek($middle - $filesize, SEEK_CUR));
        TestCase::assertEquals($middle, $stream->tell());
        TestCase::assertEquals(substr($content, $middle, 50), $stream->read(50));
        TestCase::assertEquals($current = min($middle + 50, $filesize), $stream->tell());

        TestCase::assertSame($stream, $stream->seek($middle - $current - 5, SEEK_CUR));
        TestCase::assertEquals($middle - 5, $stream->tell());
        TestCase::assertEquals(substr($content, $middle - 5, 50), $stream->read(50));
        TestCase::assertEquals($current = min($middle + 45, $filesize), $stream->tell());

        // 定位到开头
        TestCase::assertSame($stream, $stream->seek(-$current, SEEK_CUR));
        TestCase::assertEquals(0, $stream->tell());
        TestCase::assertEquals(substr($content, 0, 50), $stream->read(50));
        TestCase::assertEquals($current = min(50, $filesize), $stream->tell());

        TestCase::assertSame($stream, $stream->seek(1 - $current, SEEK_CUR));
        TestCase::assertEquals(1, $stream->tell());
        TestCase::assertEquals(substr($content, 1, 50), $stream->read(50));
        TestCase::assertEquals($current = min(51, $filesize), $stream->tell());

        // 定位到结尾
        TestCase::assertSame($stream, $stream->seek($filesize - $current, SEEK_CUR));
        TestCase::assertEquals($filesize, $stream->tell());
        TestCase::assertEquals('', $stream->read(50));
        TestCase::assertEquals($filesize, $stream->tell());

        TestCase::assertSame($stream, $stream->seek(-1, SEEK_CUR));
        TestCase::assertEquals($filesize - 1, $stream->tell());
        TestCase::assertEquals(substr($content, $filesize - 1), $stream->read(5));
        TestCase::assertEquals($filesize, $stream->tell());

        // SEEK_END: 定位到结尾
        TestCase::assertSame($stream, $stream->seek(0, SEEK_END));
        TestCase::assertEquals($filesize, $stream->tell());
        TestCase::assertEquals('', $stream->read(5));
        TestCase::assertEquals($filesize, $stream->tell());

        TestCase::assertSame($stream, $stream->seek(-1, SEEK_END));
        TestCase::assertEquals($filesize - 1, $stream->tell());
        TestCase::assertEquals(substr($content, -1), $stream->read(5));
        TestCase::assertEquals($filesize, $stream->tell());

        // 定位到开头
        TestCase::assertSame($stream, $stream->seek(-$filesize, SEEK_END));
        TestCase::assertEquals(0, $stream->tell());
        TestCase::assertEquals(substr($content, 0, 50), $stream->read(50));
        TestCase::assertEquals(min(50, $filesize), $stream->tell());

        TestCase::assertSame($stream, $stream->seek(-$filesize + 1, SEEK_END));
        TestCase::assertEquals(1, $stream->tell());
        TestCase::assertEquals(substr($content, 1, 50), $stream->read(50));
        TestCase::assertEquals(min(51, $filesize), $stream->tell());

        // 定位到中间
        TestCase::assertSame($stream, $stream->seek($middle - $filesize, SEEK_END));
        TestCase::assertEquals($middle, $stream->tell());
        TestCase::assertEquals(substr($content, $middle, 50), $stream->read(50));
        TestCase::assertEquals(min($filesize, $middle + 50), $stream->tell());
    }
}
