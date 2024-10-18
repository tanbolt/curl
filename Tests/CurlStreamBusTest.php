<?php
require_once __DIR__.'/CurlStreamTest.php';

use Tanbolt\Curl\Stream;
use Tanbolt\Curl\StreamBus;
use PHPUnit\Framework\TestCase;
use Tanbolt\Curl\StreamInterface;

class CurlStreamBusTest extends TestCase
{
    public function testStreamBus()
    {
        $filepath = __DIR__.'/Fixtures/stream.txt';
        $filepath2 = __DIR__.'/Fixtures/change5.jpg';
        $content = file_get_contents($filepath);
        $fpStream = new Stream(fopen($filepath2, 'rb'));
        $fileStream = new Stream($filepath);

        $stream = new StreamBus();
        self::assertSame($stream, $stream->addString($content));
        self::assertSame($stream, $stream->addStream($fpStream));
        self::assertSame($stream, $stream->addStream($fileStream));

        self::assertTrue($stream->isSeekable());
        self::assertTrue($stream->isReadable());
        self::assertFalse($stream->isWritable());

        $contents = [$content, file_get_contents($filepath2), $content];
        foreach ($stream->streams() as $k => $item) {
            self::assertInstanceOf(StreamInterface::class, $item);
            self::assertEquals($contents[$k], $item->content());
            $item->rewind();
        }

        $actual = join('', $contents);
        $filesize = strlen($actual);
        static::assertEquals($filesize, $stream->size());
        static::assertEquals($actual, $stream->content());

        $buffer = '';
        $stream->rewind();
        while (!$stream->eof()) {
            $buffer .= $stream->read(8192);
        }
        static::assertEquals($buffer, $buffer);

        // 超出范围的 seek
        CurlStreamTest::checkExceedsSeek($stream, $filesize);

        // 正常范围内的 seek
        CurlStreamTest::checkOffsetSeek($stream, $actual);
    }
}
