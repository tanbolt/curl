<?php
use Tanbolt\Curl\Response;
use PHPUnit\Framework\TestCase;
use Tanbolt\Curl\Stream;

class CurlResponseTest extends TestCase
{
    public function testProperty()
    {
        $res = new Response();

        // error
        self::assertNull($res->error);
        self::assertSame($res, $res->__setResponseValue('error', $err = new Exception('err')));
        self::assertSame($err, $res->error);
        $res->__setResponseValue('error', null);
        self::assertNull($res->error);

        // isCallbackError
        self::assertFalse($res->isCallbackError);
        $res->__setResponseValue('isCallbackError', true);
        self::assertTrue($res->isCallbackError);
        $res->__setResponseValue('isCallbackError', false);
        self::assertFalse($res->isCallbackError);

        // tryTime
        self::assertEquals(0, $res->tryTime);
        $res->__setResponseValue('tryTime', 1);
        self::assertEquals(1, $res->tryTime);
        $res->__setResponseValue('tryTime', 0);
        self::assertEquals(0, $res->tryTime);

        // redirects
        self::assertEquals([], $res->redirects);
        $res->__setResponseValue('redirects', ['url']);
        self::assertEquals(['url'], $res->redirects);
        $res->__setResponseValue('redirects', []);
        self::assertEquals([], $res->redirects);

        // version
        self::assertNull($res->version);
        $res->__setResponseValue('version', 1.1);
        self::assertEquals(1.1, $res->version);
        $res->__setResponseValue('version', null);
        self::assertNull($res->version);

        // code
        self::assertNull($res->code);
        $res->__setResponseValue('code', 200);
        self::assertEquals(200, $res->code);
        $res->__setResponseValue('code', null);
        self::assertNull($res->code);

        // message
        self::assertNull($res->message);
        $res->__setResponseValue('message', 'ok');
        self::assertEquals('ok', $res->message);
        $res->__setResponseValue('message', null);
        self::assertNull($res->message);

        // url
        self::assertNull($res->url);
        $res->__setResponseValue('url', 'http://foo.com');
        self::assertEquals('http://foo.com', $res->url);
        $res->__setResponseValue('url', null);
        self::assertNull($res->url);

        // charset
        self::assertNull($res->charset);
        $res->__setResponseValue('charset', 'utf-8');
        self::assertEquals('UTF-8', $res->charset);
        $res->__setResponseValue('charset', null);
        self::assertNull($res->charset);

        // debug
        self::assertNull($res->debug);
        $res->__setResponseValue('debug', 'debug');
        self::assertEquals('debug', $res->debug);
        $res->__setResponseValue('debug', null);
        self::assertNull($res->debug);
    }

    public function testHeader()
    {
        $res = new Response();

        // code version
        self::assertEquals([200, 1.1], $res->__putHeaderLine('HTTP/1.1 200 OK'));
        self::assertEquals(200, $res->code);
        self::assertEquals(1.1, $res->version);
        self::assertEquals('OK', $res->message);

        // header
        self::assertEquals(['Connection' => 'keep-alive'], $res->__putHeaderLine('Connection: keep-alive'));
        self::assertEquals(
            ['Content-Type' => 'text/html; charset=utf-8'],
            $res->__putHeaderLine('content-type: text/html; charset=utf-8')
        );
        self::assertEquals(['Set-Cookie' => 'foo=foo'], $res->__putHeaderLine('Set-Cookie: foo=foo'));
        self::assertEquals(['Set-Cookie' => 'bar=bar; path=/'], $res->__putHeaderLine('set-cookie: bar=bar; path=/'));
        self::assertEquals($headers = [
            'Connection' => 'keep-alive',
            'Content-Type' => 'text/html; charset=utf-8',
            'Set-Cookie' => [
                'foo=foo',
                'bar=bar; path=/'
            ]
        ], $res->headers);
        foreach ($headers as $key => $value) {
            self::assertEquals($value, $res->header($key));
        }
        self::assertNull($res->header('none'));
        self::assertEquals('def', $res->header('none', 'def'));

        // contentType, charset
        self::assertEquals('text/html', $res->contentType);
        self::assertEquals('UTF-8', $res->charset);

        // end
        self::assertNull($res->__putHeaderLine('errorHeader'));
        self::assertTrue($res->__putHeaderLine(''));

        // 再次设置
        self::assertEquals([301, 2], $res->__putHeaderLine('HTTP/2 301 Moved Permanently'));
        self::assertEquals(301, $res->code);
        self::assertEquals(2, $res->version);
        self::assertEquals('Moved Permanently', $res->message);
        self::assertEquals([], $res->headers);
        self::assertNull($res->contentType);

        // 已设置 charset, 不能重置
        $res = new Response();
        $res->__setResponseValue('charset', 'gbk');
        self::assertEquals('GBK', $res->charset);

        $res->__putHeaderLine('content-type: text/html; charset=utf-8');
        self::assertEquals('GBK', $res->charset);
    }

    public function testInfo()
    {
        $res = new Response();
        $info = [
            'foo' => 'foo',
            'bar' => 'bar',
            'biz' => 'biz'
        ];
        self::assertEquals([], $res->info);
        $res->__setResponseValue('info', $info);

        $realInfo = array_merge($info, ['redirect_count' => 0]);
        self::assertEquals($realInfo, $res->info);
        foreach ($realInfo as $key => $value){
            self::assertEquals($value, $res->info($key));
        }
        self::assertNull($res->info('none'));
        self::assertEquals('def', $res->info('none', 'def'));

        // 重定向次数
        $res->__setResponseValue('redirects', [['url', 'url', 'url']]);
        $res->__setResponseValue('info', $info);
        $realInfo = array_merge($info, ['redirect_count' => 2]);
        self::assertEquals($realInfo, $res->info);
        foreach ($realInfo as $key => $value){
            self::assertEquals($value, $res->info($key));
        }
    }

    public function testBody()
    {
        $res = new Response();
        self::assertNull($res->body);
        self::assertNull($res->length);
        self::assertNull($res->content());
        self::assertNull($res->json());

        $fp = fopen('php://memory', 'r+b');
        fwrite($fp, $str = 'body_content');
        $body = new Stream($fp);
        $res->__setResponseValue('body', $body);

        self::assertSame($body, $res->body);
        self::assertEquals(strlen($str), $res->length);
        self::assertEquals($str, $res->content());
        self::assertNull($res->json());

        $arr = ['foo' => 'foo', 'bar' => 'bar'];
        $json = json_encode($arr);
        $body->truncate(0)->write($json);

        self::assertEquals(strlen($json), $res->length);
        self::assertEquals($json, $res->content());
        self::assertEquals($arr, $res->json());

        $body->close();
    }

    public function testStart()
    {
        $res = new Response();
        $res->__putHeaderLine('HTTP/1.1 200 OK');
        $res->__putHeaderLine('content-type: text/html; charset=utf-8');
        $res->__setResponseValue('info', ['foo' => 'foo']);

        self::assertSame($res, $res->__setResponseValue('start', true));
        self::assertNull($res->version);
        self::assertNull($res->code);
        self::assertNull($res->message);
        self::assertNull($res->contentType);
        self::assertEquals([], $res->headers);
        self::assertEquals([], $res->info);
    }

    public function testEnd()
    {
        $redirects = [
            ['foo', 'bar']
        ];
        $res = new Response();
        $res->__setResponseValue('redirects', $redirects);

        $fp = fopen('php://memory', 'r+b');
        fwrite($fp, '12345');
        $res->__setResponseValue('body', $body = new Stream($fp));

        self::assertEquals('123', $body->rewind()->read(3));
        self::assertSame($res, $res->__setResponseValue('end', true));
        self::assertEquals($redirects[0], $res->redirects);
        self::assertSame('123', $res->body->read(3));

        $redirects[] = ['foo2', 'bar2'];
        $res = new Response();
        $res->__setResponseValue('redirects', $redirects);
        self::assertSame($res, $res->__setResponseValue('end', true));
        self::assertEquals($redirects, $res->redirects);
    }

    public function testMayLoop()
    {
        $res = new Response();
        $test = [
            'http://foo.com_HTTP/1.1 200 OK',
            'http://foo.com_HTTP/2 301 Moved Permanently',
            'http://bar.com_HTTP/1.1 200 OK',
            'http://bar.com_HTTP/2 301 Moved Permanently',
            'http://foo.com_HTTP/1.1 200 OK',
            'http://foo.com_HTTP/2 301 Moved Permanently',
            'http://foo.com_HTTP/1.1 200 OK',
        ];
        // 相同 url response 返回超过 3 次, 认为死循环了
        $last = count($test) - 1;
        foreach ($test as $key => $item) {
            list($url, $header) = explode('_', $item);
            $res->__setResponseValue('url', $url);
            $res->__putHeaderLine($header);
            if ($key === $last) {
                self::assertTrue($res->__mayLoop());
            } else {
                self::assertFalse($res->__mayLoop());
            }
        }
    }
}
