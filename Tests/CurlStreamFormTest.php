<?php
require_once __DIR__.'/CurlStreamTest.php';

use Tanbolt\Curl\StreamForm;
use PHPUnit\Framework\TestCase;

class CurlStreamFormTest extends TestCase
{
    public function testConstruct()
    {
        $filepath = __DIR__.'/Fixtures/stream.txt';
        $content = file_get_contents($filepath);

        $stream = new StreamForm();
        static::assertSame($stream, $stream->addParam('foo', 'foo'));
        static::assertSame($stream, $stream->addFile('file', $filepath));
        $this->checkStreamForm($stream, $content);

        $stream = new StreamForm();
        static::assertSame($stream, $stream->addParam('foo', 'foo'));
        static::assertSame($stream, $stream->addFileContent(
            'file', $content, ['filename' => 'stream.txt', 'mimetype' => 'text/plain']
        ));
        $this->checkStreamForm($stream, $content);
    }

    protected function checkStreamForm(StreamForm $stream, $content)
    {
        static::assertTrue($stream->isSeekable());
        static::assertTrue($stream->isReadable());
        static::assertFalse($stream->isWritable());

        $actual =$this->getPostData($stream->boundary(), $content);
        static::assertEquals(strlen($actual), $stream->size());
        static::assertEquals($actual, $stream->content());

        $buffer = '';
        $stream->rewind();
        while (!$stream->eof()) {
            $buffer .= $stream->read(8192);
        }
        static::assertEquals($buffer, $buffer);
    }

    protected function getPostData($boundary, $content)
    {
        $crlf = "\r\n";
        return "--$boundary".$crlf.
        'Content-Disposition: form-data; name="foo"'.$crlf.$crlf.
        'foo'.$crlf.
        "--$boundary".$crlf.
        'Content-Disposition: form-data; name="file"; filename="stream.txt"'.$crlf.
        'Content-Type: text/plain'.$crlf.$crlf.
        $content.$crlf.
        "--$boundary--".$crlf;
    }

    // 在 Curl 中不会用到 seek 操作, 但实现了该功能，这里也测试一下
    public function testReadStream()
    {
        $filepath = __DIR__.'/Fixtures/stream.txt';

        $stream = new StreamForm();
        static::assertSame($stream, $stream->addParam('foo', 'foo'));
        static::assertSame($stream, $stream->addFile('file', $filepath));

        $content = $this->getPostData($stream->boundary(), file_get_contents($filepath));
        $filesize = strlen($content);

        // 超出范围的 seek
        CurlStreamTest::checkExceedsSeek($stream, $filesize);

        // 正常范围内的 seek
        CurlStreamTest::checkOffsetSeek($stream, $content);
    }
}
