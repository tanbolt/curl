<?php
require_once __DIR__.'/Server/SimpleTempServer.php';

use Tanbolt\Curl\Curl;
use Tanbolt\Curl\Stream;
use Tanbolt\Curl\Request;
use Tanbolt\Curl\Response;
use Tanbolt\Curl\UserAgent;
use PHPUnit\Framework\TestCase;
use Tanbolt\Curl\TransportInterface;

/**
 * Class LibTransportCase
 * 测试 TransportInterface 通信驱动, 只需实现 driver() 方法即可
 */
abstract class CurlTransportCase extends TestCase
{
    use SimpleTempServer;

    /**
     * 要测试的 Driver
     * @return string|TransportInterface
     */
    abstract public static function driver();

    public static function setUpBeforeClass():void
    {
        $error = $driver = null;
        try {
            $driver = Curl::driver(static::driver());
        } catch (Throwable $e) {
            $error = $e;
        }
        if ($error) {
            static::markTestIncomplete('Create driver instance failed: ['.$error->getMessage().'], skip test');
        } elseif (!$driver instanceof TransportInterface) {
            static::markTestIncomplete('Driver is not instanceof TransportInterface, skip test');
        } elseif (!static::startServer()) {
            static::markTestIncomplete('Start test server failed, skip test');
        } else {
            Curl::$defaultDriver = $driver;
        }
        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass():void
    {
        static::stopServer();
        parent::tearDownAfterClass();
    }

    public static function request($path, $method = null)
    {
        return Curl::request(static::$urlPrefix.'/'.$path, $method);
    }

    protected static function assertSubArray($expected, $result)
    {
       static::assertTrue(
           empty(array_diff_assoc($expected, $result))
       );
    }

    public function testBasic()
    {
        $query = '?ac=basic';
        $res = static::request($query)->send();
        self::assertBasicResponse($res);
        self::assertEquals([static::$urlPrefix.'/'.$query], $res->redirects);

        $res = static::request($query)->debug()->send();
        self::assertInstanceOf(Stream::class, $res->debug);
        self::assertGreaterThan(0, $res->debug->size());
        self::assertNotEmpty($res->debug->content());
    }

    protected static function assertBasicResponse(Response $res)
    {
        self::assertBasicHeader($res);
        self::assertNull($res->error);
        self::assertSubArray([
            'Host' => static::$serverHost.':'.static::$serverPort,
            'X-Custom' => 'Tanbolt',
            'Content-Type' => 'text/html; charset=UTF-8',
        ], $res->headers);
        self::assertEquals('text/html', $res->contentType);
        self::assertEquals('UTF-8', $res->charset);

        $json = ['rs' => 'success'];
        $content = json_encode($json);
        self::assertInstanceOf(Stream::class, $res->body);
        self::assertEquals($content, $res->content());
        self::assertEquals(strlen($content), $res->length);
        self::assertEquals($json, $res->json());
        self::assertNull($res->debug);
    }

    protected static function assertBasicHeader(Response $res)
    {
        self::assertEquals(0, $res->tryTime);
        self::assertEquals(static::$urlPrefix.'/?ac=basic', $res->url);
        self::assertEquals(1.1, $res->version);
        self::assertEquals(200, $res->code);
        self::assertEquals('OK', $res->message);
    }


    public function testTimeout()
    {
        $res = static::request('?ac=timeout')->timeout(1)->send();
        self::assertEquals(200, $res->code);
        self::assertEquals('timeout', $res->content());

        // 超时
        $res = static::request('?ac=timeout&sleep=1500000')->timeout(1)->send();
        self::assertNotNull($res->error);
    }

    public function testTryTime()
    {
        // 没想好怎么测试
        static::assertTrue(true);
    }

    public function testCharset()
    {
        $res = static::request('?ac=charset')->send();
        self::assertEquals(200, $res->code);
        self::assertEquals('text/plain; charset=GBK', $res->header('Content-Type'));
        self::assertEquals('中文', $res->content());

        $res = static::request('?ac=charset&type=none')->send();
        self::assertEquals(200, $res->code);
        self::assertEquals('text/plain', $res->header('Content-Type'));
        self::assertNotEquals('中文', $res->content());

        $res = static::request('?ac=charset&type=none')->charset('gbk')->send();
        self::assertEquals(200, $res->code);
        self::assertEquals('text/plain', $res->header('Content-Type'));
        self::assertEquals('中文', $res->content());

        $html = file_get_contents(__DIR__ . '/Server/gbk.html');
        $html = str_replace('{body}', '中文', $html);
        $res = static::request('?ac=charset&type=html')->send();
        self::assertEquals(200, $res->code);
        self::assertEquals('text/html', $res->header('Content-Type'));
        self::assertEquals($html, $res->content());
    }

    public function testEncoding()
    {
        $decode = 'encoding';
        $encode = gzencode($decode, 9);
        $res = static::request('?ac=encoding')->send();
        self::assertEquals(200, $res->code);
        self::assertEquals($encode, $res->content());

        $res = static::request('?ac=encoding')->useEncoding()->send();
        self::assertEquals(200, $res->code);
        self::assertEquals($decode, $res->content());
    }

    public function testMethod()
    {
        $res = static::request('?ac=method', 'head')->send();
        self::assertEquals(200, $res->code);
        self::assertNull($res->body);
        foreach (['GET', 'POST', 'PUT', 'DELETE', 'TRACE', 'PATCH'] as $method) {
            self::assertEquals($method, static::request('?ac=method', $method)->send()->content());
        }
    }

    public function testHeader()
    {
        $res = static::request('?ac=header')->headers([
            'X-Request' => 'Tanbolt',
            'cookie' => ['foo=foo', 'bar=bar']
        ])->userAgent(UserAgent::ANDROID)->send();
        self::assertSubArray([
            'X-Request' => 'Tanbolt',
            'Cookie' => 'foo=foo; bar=bar',
            'User-Agent' => UserAgent::ANDROID,
        ], $res->json());
    }

    public function testCookie()
    {
        $reqCookie = 'foo=foo; bar=bar';
        $cookie = ['foo' => 'foo', 'bar' => 'bar'];

        $res = static::request('?ac=cookie')->cookie($reqCookie)->send();
        self::assertEquals($cookie, $res->json());

        // 同时发送 自定义 cookie 和 server set-cookie (测试 cooke remove / cookie path)
        $res = static::request('?ac=cookie&set=yes')->cookie($reqCookie)->send();
        self::assertEquals(array_merge(['sa' => 'sa', 'sc' => 'sc'], $cookie), $res->json());

        $res = static::request('?ac=cookie&set=yes&rm=1')->cookie($reqCookie)->send();
        self::assertEquals(array_merge(['sa' => 'sa'], $cookie), $res->json());

        $res = static::request('path?ac=cookie&set=yes')->cookie($reqCookie)->send();
        self::assertEquals(array_merge(['sa' => 'sa', 'sb' => 'sb', 'sc' => 'sc'], $cookie), $res->json());

        // 发出一个新请求, 不应使用上一次遗留的 server set-cookie
        $res = static::request('?ac=cookie')->send();
        self::assertEquals([], $res->json());

        // 禁止自动 cookie, 那么即使在一次请求内, 也不使用 server 的 set-cookie
        $res = static::request('?ac=cookie&set=yes')->autoCookie(false)->cookie($reqCookie)->send();
        self::assertEquals($cookie, $res->json());

        // 禁止后再次开启, 仍可同时使用 自定义 cookie 和 server set-cookie
        $res = static::request('?ac=cookie&set=yes')->cookie($reqCookie)->send();
        self::assertEquals(array_merge(['sa' => 'sa', 'sc' => 'sc'], $cookie), $res->json());

        // 在 onResponse 手动发起新请求, 此时还未缓存 server set-cookie
        $x = 0;
        $res = static::request('?ac=cookie&set=yes')->onResponse(function ($a, $b, Request $req) use (&$x){
            if (!$x) {
                $x = 1;
                $req->url(static::$urlPrefix.'/?ac=cookie');
                return true;
            }
        })->cookie($reqCookie)->send();
        self::assertEquals($cookie, $res->json());

        // 在 onHeaderLine 手动发起新请求, 此时仅缓存一个 server set-cookie
        $x = 0;
        $res = static::request('?ac=cookie&set=yes')->onHeaderLine(function ($key, $val, Request $req) use (&$x){
            if (!$x && 'Set-Cookie' === $key) {
                $x = 1;
                $req->url(static::$urlPrefix.'/?ac=cookie');
                return true;
            }
        })->cookie($reqCookie)->send();
        self::assertEquals(array_merge(['sa' => 'sa'], $cookie), $res->json());

        // 在 onHeader 手动发起新请求, 此时已缓存所有 server set-cookie
        $x = 0;
        $res = static::request('?ac=cookie&set=yes')->onHeader(function ($key, $val, Request $req) use (&$x){
            if (!$x) {
                $x = 1;
                $req->url(static::$urlPrefix.'/?ac=cookie');
                return true;
            }
        })->cookie($reqCookie)->send();
        self::assertEquals(array_merge(['sa' => 'sa', 'sc' => 'sc'], $cookie), $res->json());
    }

    // 测试了 auth, allowError, hostResolver, alwaysAuth
    public function testAuth()
    {
        // basic
        $res = static::request('?ac=bauth')->send();
        self::assertInstanceOf(Throwable::class, $res->error);

        $res = static::request('?ac=bauth')->allowError()->send();
        self::assertEquals(401, $res->code);

        $res = Curl::request(
            'http://user:pass@'.static::$serverHost.':'.static::$serverPort.'/?ac=bauth'
        )->allowError()->send();
        self::assertEquals(200, $res->code);
        self::assertEquals('user', $res->content());

        $res = static::request('?ac=bauth')->auth('user', 'pass')->allowError(false)->send();
        self::assertEquals(200, $res->code);
        self::assertEquals('user', $res->content());

        // digest
        $res = static::request('?ac=auth')->send();
        self::assertInstanceOf(Throwable::class, $res->error);

        $res = static::request('?ac=auth')->allowError()->send();
        self::assertEquals(401, $res->code);

        $res = static::request('?ac=auth')->auth('user', 'pass')->allowError(false)->send();
        self::assertEquals(200, $res->code);
        self::assertEquals('user', $res->content());

        $port = static::$serverPort;

        // 测试 hostResolver
        $res = Curl::request('http://example.dev:'.$port.'/?ac=auth')->hostResolver([
            'example.dev:'.$port.':127.0.0.1'
        ])->auth('user', 'pass')->send();
        self::assertEquals(200, $res->code);
        self::assertEquals('user', $res->content());

        // 测试 alwaysAuth = false
        $res = Curl::request('http://example.dev:'.$port.'/?ac=auth')->hostResolver([
            'example.dev:'.$port.':127.0.0.1', 'test.dev:'.$port.':127.0.0.1'
        ])->putQuery(
            'go', 'http://test.dev:'.$port
        )->auth('user', 'pass')->allowError()->send();
        self::assertEquals(401, $res->code);

        // 测试 alwaysAuth = true
        $res = Curl::request('http://example.dev:'.$port.'/?ac=auth')->hostResolver([
            'example.dev:'.$port.':127.0.0.1', 'test.dev:'.$port.':127.0.0.1'
        ])->putQuery(
            'go', 'http://test.dev:'.$port
        )->auth('user', 'pass')->alwaysAuth()->send();
        self::assertEquals(200, $res->code);
        self::assertEquals('user', $res->content());
    }

    public function testQueries()
    {
        // url query
        $query = [
            'ac' => 'param',
            'foo' => 'foo',
            'bar' => 'bar'
        ];
        $res = static::request('?'.http_build_query($query))->send();
        self::assertEquals(json_encode(['get' => $query]), $res->content());

        // extra query
        $appendQuery = [
            'biz' => 'biz',
            'zh' => ['nihao', '你好']
        ];
        $res = static::request('?'.http_build_query($query))->queries($appendQuery)->send();
        $finalQuery = array_merge($query, $appendQuery);
        self::assertEquals(json_encode(['get' => $finalQuery]), $res->content());
    }

    public function testParams()
    {
        // params
        $query = [
            'ac' => 'param',
        ];
        $params = [
            'foo' => 'foo',
            'bar' => 'bar'
        ];
        $res = static::request('?'.http_build_query($query))->params($params)->send();
        self::assertEquals(json_encode([
            'get' => $query,
            'post' => $params
        ]), $res->content());

        // params + files
        $res = static::request('?'.http_build_query($query))
            ->params($params)
            ->putFile('change5', __DIR__.'/Fixtures/change5.jpg')
            ->putFile('stream', __DIR__.'/Fixtures/stream.txt', 'test.gif')
            ->send();
        $json = $res->json();
        self::assertEquals($query, $json['get']);
        self::assertEquals($params, $json['post']);
        self::assertEquals(['change5', 'stream'], array_keys($json['file']));
        self::assertSubArray([
            'name' => 'change5.jpg',
            'type' => 'image/jpeg',
            'error' => 0,
            'size' => filesize(__DIR__.'/Fixtures/change5.jpg')
        ], $json['file']['change5']);
        self::assertSubArray([
            'name' => 'test.gif',
            'type' => 'image/gif',
            'error' => 0,
            'size' => filesize(__DIR__.'/Fixtures/stream.txt')
        ], $json['file']['stream']);
    }

    public function testRedirect()
    {
        // 普通 get 跳转
        $res = static::request('?ac=redirect&codes=301-302')->debug()->send();
        self::assertEquals(200, $res->code);
        self::assertEquals(static::$urlPrefix.'/?ac=body', $res->url);
        self::assertEquals(static::$urlPrefix.'/?ac=redirect&codes=301-302&step=1', $res->header('Request-Referer'));
        self::assertEquals('body:', $res->content());
        self::assertEquals([
            static::$urlPrefix.'/?ac=redirect&codes=301-302',
            static::$urlPrefix.'/?ac=redirect&codes=301-302&step=1',
            static::$urlPrefix.'/?ac=body',
        ], $res->redirects);

        // 禁用 Referer
        $res = static::request('?ac=redirect&codes=301')->autoReferrer(false)->send();
        self::assertEquals(200, $res->code);
        self::assertEquals(static::$urlPrefix.'/?ac=body', $res->url);
        self::assertEmpty($res->header('Request-Referer'));
        self::assertEquals('body:', $res->content());

        // 禁止跳转
        $res = static::request('?ac=redirect&codes=301')->autoRedirect(false)->send();
        self::assertEquals(301, $res->code);
        self::assertEmpty($res->content());

        // 限制跳转次数
        $res = static::request('?ac=redirect&codes=301-302')->maxRedirect(2)->send();
        self::assertEquals(200, $res->code);
        self::assertEquals(static::$urlPrefix.'/?ac=body', $res->url);
        self::assertEquals('body:', $res->content());
        self::assertCount(3, $res->redirects);

        $res = static::request('?ac=redirect&codes=301-302-303')->maxRedirect(2)->send();
        self::assertEquals(303, $res->code);
        self::assertEquals(static::$urlPrefix.'/?ac=redirect&codes=301-302-303&step=2', $res->url);
        self::assertCount(3, $res->redirects);

        // 301/302/303 不发送 body
        $res = static::request('?ac=redirect&codes=301', 'DELETE')->body('test')->send();
        self::assertEquals(200, $res->code);
        self::assertEquals(static::$urlPrefix.'/?ac=body', $res->url);
        self::assertEquals('DELETE', $res->header('Request-Method'));
        self::assertEquals('body:', $res->content());

        // POST PUT 转 get
        $res = static::request('?ac=redirect&codes=301-302')->body('test')->send();
        self::assertEquals(200, $res->code);
        self::assertEquals(static::$urlPrefix.'/?ac=body', $res->url);
        self::assertEquals('GET', $res->header('Request-Method'));
        self::assertEquals('body:', $res->content());

        $res = static::request('?ac=redirect&codes=301-302', 'PUT')->body('test')->send();
        self::assertEquals(200, $res->code);
        self::assertEquals(static::$urlPrefix.'/?ac=body', $res->url);
        self::assertEquals('GET', $res->header('Request-Method'));
        self::assertEquals('body:', $res->content());

        // 300/307/308 跳转后继续发送 body 且保持 method
        $res = static::request('?ac=redirect&codes=300-307')->body('test')->send();
        self::assertEquals(200, $res->code);
        self::assertEquals(static::$urlPrefix.'/?ac=body', $res->url);
        self::assertEquals('POST', $res->header('Request-Method'));
        self::assertEquals('body:test', $res->content());

        $res = static::request('?ac=redirect&codes=308', 'PUT')->body('test')->send();
        self::assertEquals(200, $res->code);
        self::assertEquals(static::$urlPrefix.'/?ac=body', $res->url);
        self::assertEquals('PUT', $res->header('Request-Method'));
        self::assertEquals('body:test', $res->content());

        // 测试跳转总时间超时
        $res = static::request('?ac=redirect&codes=301-302-303')->timeout(1)->send();
        self::assertEquals(200, $res->code);
        self::assertEquals('body:', $res->content());

        // 超时
        $res = static::request('?ac=redirect&codes=301-302-303&sleep=500000')->timeout(1)->send();
        self::assertNotNull($res->error);
    }

    public function testBody()
    {
        $file = __DIR__.'/Fixtures/change5.jpg';

        // 上传 Stream 有可能使用 Expect: 100-continue
        $res = static::request('?ac=body')->body('file://'.$file)->send();
        self::assertContains($res->header('Request-Expect'), ['100-continue', '']);
        self::assertEmpty($res->header('Request-Transfer-Encoding'));
        self::assertEquals(filesize($file), $res->header('Request-Content-Length'));
        self::assertEquals('image/jpeg', $res->header('Request-Content-Type'));
        self::assertEquals('body:'.file_get_contents($file), $res->content());

        // file body, 禁用 Expect + 服务端响应为 chunked
        $res = static::request('?ac=body&chunked=1')->body('file://'.$file)->disableExpect()->send();
        self::assertEmpty($res->header('Request-Expect'));
        self::assertEmpty($res->header('Request-Transfer-Encoding'));
        self::assertEquals(filesize($file), $res->header('Request-Content-Length'));
        self::assertEquals('image/jpeg', $res->header('Request-Content-Type'));
        self::assertEquals('body:'.file_get_contents($file), $res->content());

        // resource body
        $res = static::request('?ac=body')->body($fp = fopen($file, 'rb'))->disableExpect()->send();
        self::assertEmpty($res->header('Request-Transfer-Encoding'));
        self::assertEquals(filesize($file), $res->header('Request-Content-Length'));
        self::assertEquals('image/jpeg', $res->header('Request-Content-Type'));
        self::assertEquals('body:'.file_get_contents($file), $res->content());

        // stream body
        $stream = new Stream($file);
        $res = static::request('?ac=body')->body($stream)->disableExpect()->send();
        self::assertEmpty($res->header('Request-Transfer-Encoding'));
        self::assertEquals($stream->size(), $res->header('Request-Content-Length'));
        self::assertEquals('image/jpeg', $res->header('Request-Content-Type'));
        self::assertEquals('body:'.file_get_contents($file), $res->content());

        // chunked(unknown length) stream body
        $stream = new PHPUNIT_ChunkedStream($file);
        $res = static::request('?ac=body')->body($stream)->disableExpect()->send();
        self::assertEquals('chunked', $res->header('Request-Transfer-Encoding'));
        self::assertEmpty($res->header('Request-Content-Length'));
        self::assertEquals('image/jpeg', $res->header('Request-Content-Type'));
        self::assertEquals('body:'.file_get_contents($file), $res->content());

        // string body
        $res = static::request('?ac=body')->body($body = 'body')->disableExpect()->send();
        self::assertEmpty($res->header('Request-Transfer-Encoding'));
        self::assertEquals(strlen($body), $res->header('Request-Content-Length'));
        self::assertEquals('application/octet-stream', $res->header('Request-Content-Type'));
        self::assertEquals('body:'.$body, $res->content());

        // array body
        $res = static::request('?ac=body')->body($body = ['foo' => 'foo'])->disableExpect()->send();
        self::assertEmpty($res->header('Request-Transfer-Encoding'));
        self::assertEquals(strlen($body = json_encode($body)), $res->header('Request-Content-Length'));
        self::assertEquals('application/json', $res->header('Request-Content-Type'));
        self::assertEquals('body:'.$body, $res->content());

        // body with contentType
        $res = static::request('?ac=body')->body($body = 'text', $type = 'text/plain')->disableExpect()->send();
        self::assertEmpty($res->header('Request-Transfer-Encoding'));
        self::assertEquals(strlen($body), $res->header('Request-Content-Length'));
        self::assertEquals($type, $res->header('Request-Content-Type'));
        self::assertEquals('body:'.$body, $res->content());

        // body with contentType + header
        $res = static::request('?ac=body')->body($body = 'text', 'text/plain')->disableExpect()->putHeader(
            'content-type', $type = 'text/css'
        )->send();
        self::assertEquals($type, $res->header('Request-Content-Type'));
        self::assertEquals('body:'.$body, $res->content());
    }

    public function testSave()
    {
        $clean = false;
        $file = __DIR__.'/Fixtures/temp/save.txt';
        $file2 = __DIR__.'/Fixtures/temp/save2.txt';
        if (is_file($file)) {
            $clean = true;
            unlink($file);
        }
        if (is_file($file2)) {
            $clean = true;
            unlink($file2);
        }
        if ($clean) {
            rmdir(dirname($file));
        }

        // 保存到 php://temp
        $res = static::request('?ac=body')->body($body = 'text')->disableExpect()->send();
        self::assertNull($res->error);
        self::assertEquals(strlen($body), $res->header('Request-Content-Length'));
        self::assertEquals('body:'.$body, $res->content());

        // 可以保存到指定的 resource
        $fp = fopen('php://memory', 'r+b');
        $res = static::request('?ac=body')->body($body = 'text')->disableExpect()
            ->makeDirAuto(false)->saveTo($fp)->send();
        self::assertNull($res->error);
        self::assertSame($fp, $res->body->resource());
        self::assertEquals(strlen($body), $res->header('Request-Content-Length'));
        self::assertEquals('body:'.$body, $res->content());
        self::assertFalse(is_file($file));
        rewind($fp);
        self::assertEquals('body:'.$body, stream_get_contents($fp));
        fclose($fp);

        // 不自动创建文件夹会导致失败, 不应发出请求
        $res = static::request('?ac=body')->body($body = 'text')->disableExpect()
            ->makeDirAuto(false)->saveTo($file)->send();
        self::assertNull($res->code);
        self::assertNotNull($res->error);
        self::assertFalse(is_file($file));

        // 自动创建文件夹, 保存到文件
        $res = static::request('?ac=body')->body($body = 'text')->disableExpect()->saveTo($file)->send();
        self::assertNull($res->error);
        self::assertEquals(strlen($body), $res->header('Request-Content-Length'));
        self::assertEquals('body:'.$body, $res->content());
        self::assertFileExists($file);
        self::assertEquals($file, $res->body->original());
        self::assertEquals('body:'.$body, file_get_contents($file));
        $res->body->close();
        unlink($file);

        // 在 onResponse 中断请求, 不会下载 body, 保存文件
        $res = static::request('?ac=body')->onResponse(function ($a, $b, Request $req) {
            $req->stop();
        })->body($body = 'text')->disableExpect()->saveTo($file)->send();
        self::assertEquals(200, $res->code);
        self::assertNotNull($res->error);
        self::assertFileNotExists($file);

        // 在 onComplete 中断请求, 此时已下载完毕, 应该保留文件
        $res = static::request('?ac=body')->onComplete(function ($a, Request $req) {
            $req->stop();
        })->body($body = 'text')->disableExpect()->saveTo($file)->send();
        self::assertEquals(200, $res->code);
        self::assertNotNull($res->error);
        self::assertFileExists($file);
        self::assertEquals('body:'.$body, file_get_contents($file));
        $res->body->close();
        unlink($file);

        // 在 onResponse 发起新请求, 应该保存为新路径
        $x = 0;
        $res = static::request('?ac=body')->onResponse(function ($a, $b, Request $req) use (&$x, $file2){
            if (!$x) {
                $x = 1;
                $req->saveTo($file2);
                return true;
            }
        })->body($body = 'text')->disableExpect()->saveTo($file)->send();
        self::assertEquals(200, $res->code);
        self::assertNull($res->error);
        self::assertFileNotExists($file);
        self::assertFileExists($file2);
        self::assertEquals('body:'.$body, file_get_contents($file2));
        $res->body->close();
        unlink($file2);

        // 在 onComplete 发起新请求, 应该保存两个文件
        $x = 0;
        $res = static::request('?ac=body')->onComplete(function ($a, Request $req) use (&$x, $file2){
            if (!$x) {
                $x = 1;
                $req->saveTo($file2);
                return true;
            }
        })->body($body = 'text')->disableExpect()->saveTo($file)->send();
        self::assertEquals(200, $res->code);
        self::assertNull($res->error);
        self::assertFileExists($file);
        self::assertEquals('body:'.$body, file_get_contents($file));
        self::assertFileExists($file2);
        self::assertEquals('body:'.$body, file_get_contents($file2));
        $res->body->close();
        unlink($file);
        unlink($file2);

        rmdir(dirname($file));
    }

    /**
     * 回调函数测试
     * @dataProvider customCallback
     * @param $step
     */
    public function testCustomCallback($step)
    {
        $callValue = [];
        $lastValue = null;
        $exception = null;
        $file = 'file://'.__DIR__.'/Fixtures/change5.jpg';
        $res = static::request('?ac=call')->body($file)->disableExpect()
            ->onUpload(function ($upload, $total, Request $req) use($step, &$callValue) {
                if (0 === $step) {
                    $req->stop();
                } else {
                    if (!isset($callValue[0])) {
                        $callValue[0] = [];
                    }
                    $callValue[0][] = [$upload, $total];
                }

            })->onResponse(function ($code, $version, Request $req) use($step, &$callValue) {
                if (1 === $step) {
                    $req->stop();
                } else {
                    $callValue[1] = [$code, $version];
                }

            })->onHeaderLine(function ($key, $value, Request $req) use($step, &$callValue) {
                if (2 === $step) {
                    $req->stop();
                } else {
                    if (!isset($callValue[2])) {
                        $callValue[2] = [];
                    }
                    if (!isset($callValue[2][$key])) {
                        $callValue[2][$key] = $value;
                    } elseif (is_array($callValue[2][$key])) {
                        $callValue[2][$key][] = $value;
                    } else {
                        $callValue[2][$key] = [$callValue[2][$key], $value];
                    }
                }

            })->onHeader(function ($headers, $code, Request $req) use($step, &$callValue) {
                if (3 === $step) {
                    $req->stop();
                } else {
                    $callValue[3] = [$headers, $code];
                }

            })->onRedirect(function ($location, $url, Request $req) use($step, &$callValue, &$lastValue) {
                if (4 === $step) {
                    $req->stop();
                } else {
                    $lastValue = $callValue;
                    $lastValue[4] = [$url, $location];
                    $callValue = [];
                }

            })->onDownload(function ($down, $total, Request $req) use($step, &$callValue) {
                if (5 === $step) {
                    $req->stop();
                } else {
                    if (!isset($callValue[5])) {
                        $callValue[5] = [];
                    }
                    $callValue[5][] = [$down, $total];
                }

            })->onComplete(function (Response $response, Request $req) use($step, &$callValue) {
                if (6 === $step) {
                    $req->stop();
                } else {
                    $callValue[6] = $response;
                }

            })->onError(function ($e) use(&$exception) {
                $exception = $e;
            })->send();

        $filesize = filesize($file);
        $redirects = [
            static::$urlPrefix.'/?ac=call',
            static::$urlPrefix.'/?ac=call&end=1'
        ];
        if ($step > 4) {
            // 在跳转后才停止请求
            $preStep = 5;
            $preValue = $lastValue;
            self::assertNotEmpty($callValue);
            self::assertCount($step - 1, $callValue);
            self::assertEquals($redirects, $res->redirects);

            // onUpload
            self::assertProgress($callValue[0], $filesize);

            // onResponse
            self::assertEquals(200, $callValue[1][0]);
            self::assertEquals(1.1, $callValue[1][1]);

            // onHeaderLine
            self::assertSubArray([
                'X-Call' => 'end'
            ], $callValue[2]);
            self::assertEquals($res->headers, $callValue[2]);

            // onHeader
            self::assertEquals($callValue[2], $callValue[3][0]);
            self::assertEquals(200, $callValue[3][1]);

            // onDownload
            if ($step > 5) {
                self::assertProgress($callValue[5], $filesize);
            }

            // onComplete
            if ($step > 6) {
                self::assertSame($res, $callValue[6]);
            }
        } else {
            // 在跳转前已停止请求
            $preStep = $step;
            $preValue = $callValue;
            self::assertNull($lastValue);
        }

        // 跳转前的响应验证
        self::assertCount($preStep, $preValue);
        if ($preStep > 0) {
            // onUpload
            self::assertProgress($preValue[0], $filesize);
        }
        if ($preStep > 1) {
            // onResponse
            self::assertEquals(307, $preValue[1][0]);
            self::assertEquals(1.1, $preValue[1][1]);
        }
        if ($preStep > 2) {
            // onHeaderLine
            self::assertSubArray([
                'Location' => '/?ac=call&end=1',
                'X-Redirect' => 'call'
            ], $preValue[2]);
            if ($preStep > 4) {
                self::assertNotEquals($res->headers, $preValue[2]);
            } else {
                self::assertEquals($res->headers, $preValue[2]);
            }
        }
        if ($preStep > 3) {
            // onHeader
            self::assertEquals($preValue[2], $preValue[3][0]);
            self::assertEquals(307, $preValue[3][1]);
        }
        if ($preStep > 4) {
            // onRedirect
            self::assertEquals($redirects, $preValue[4]);
        }

        // onError: 完全成功, 不会有 error, 否则都会触发
        if ($step > 6) {
            self::assertNull($exception);
            self::assertNull($res->error);
        } else {
            self::assertNotNull($exception);
            self::assertInstanceOf(Throwable::class, $exception);
            self::assertEquals($exception, $res->error);
        }
    }

    // 上传/下载 进度监听, $total 可能为 0, 但成功后 progress 肯定等于 size
    protected static function assertProgress($progress, $size)
    {
        self::assertTrue(is_array($progress));
        self::assertNotEmpty($progress);
        $last = $progress[count($progress) - 1];
        self::assertEquals($size, $last[0]);
        self::assertTrue($size === $last[1] || 0 === $last[1]);
    }

    public function customCallback()
    {
        return [
            'onUpload' => [0],
            'onResponse' => [1],
            'onHeaderLine' => [2],
            'onHeader' => [3],
            'onRedirect' => [4],
            'onDownload' => [5],
            'onComplete' => [6],
            'onError' => [7],
        ];
    }

    /**
     * 在 callback 中重置 request, 返回 true 发起新的请求
     * @dataProvider customRedirect
     * @param $callback
     */
    public function testCustomRedirect($callback)
    {
        $finalUrl = static::$urlPrefix.'/?ac=basic';
        $req = static::request('?ac=redirect');
        if ('onComplete' === $callback) {
            $req->autoRedirect(false)->onComplete(function ($res, Request $req) use($finalUrl) {
                if (static::$urlPrefix.'/?ac=redirect' === $req->url) {
                    $req->url($finalUrl);
                    return true;
                }
            });
        } else {
            call_user_func([$req, $callback], function ($a, $b, Request $req) use($finalUrl) {
                if (static::$urlPrefix.'/?ac=redirect' === $req->url) {
                    $req->url($finalUrl);
                    return true;
                }
            });
        }
        $res = $req->send();
        self::assertBasicResponse($res);
    }

    public function customRedirect()
    {
        $customs = [];
        $methods = ['onResponse', 'onHeaderLine', 'onHeader', 'onRedirect', 'onComplete'];
        foreach ($methods as $method) {
            $customs[$method] = [$method];
        }
        return  $customs;
    }

    // 在 callback 自定义重定向导致死循环
    public function testDeadLoop()
    {
        // 直接死循环, 循环请求同一个地址
        $finalUrl = static::$urlPrefix.'/?ac=basic';
        $res = static::request('?ac=method')->onResponse(function ($a, $b, Request $req) use($finalUrl) {
            $req->url($finalUrl);
            return true;
        })->send();
        self::assertBasicHeader($res);
        self::assertNotNull($res->error);

        // 间接死循环, 兜圈请求同一个地址
        $res = Curl::request($finalUrl)->onResponse(function ($a, $b, Request $req) use($finalUrl) {
            if ($finalUrl === $req->url) {
                $req->url(static::$urlPrefix.'/?ac=method');
                return true;
            }
        })->onHeaderLine(function ($k, $v, Request $req) use($finalUrl) {
            if (static::$urlPrefix.'/?ac=method' === $req->url) {
                $req->url($finalUrl);
                return true;
            }
        })->send();
        self::assertBasicHeader($res);
        self::assertNotNull($res->error);
    }

    public function testFetchMulti()
    {
        $req1 = static::request('?ac=basic');
        $req2 = static::request('?ac=method');
        $req3 = static::request('?ac=timeout');
        $curl = new Curl();
        $curl->add($req1, $req2, $req3)->send();
        self::assertEquals('{"rs":"success"}', $req1->response->content());
        self::assertEquals('GET', $req2->response->content());
        self::assertEquals('timeout', $req3->response->content());
    }

    public function testSslServer()
    {
        // 启动 server
        if (!static::startSSLServer()) {
            static::markTestIncomplete('Start https server failed, skip test');
        }
        try {
            self::sslCheck();
        } finally {
            // 关闭 server
            static::stopSSLServer();
        }
    }

    // 测试 https 请求
    private static function sslCheck()
    {
        // CA 证书错误 -> fail
        self::assertSslFailed(
            self::sslRequest(false)
        );

        // CA 证书错误 但不校验 CA -> ok
        self::assertSslSuccess(
            self::sslRequest(false)->sslVerify(false, false)
        );

        // CA 证书正确 -> ok
        self::assertSslSuccess(
            self::sslRequest()
        );

        // CA 证书正确 但 域名不匹配 -> fail
        self::assertSslFailed(
            self::sslRequest(true, true)
        );

        // CA 证书正确 但 域名不匹配 + 不校验域名 -> ok
        self::assertSslSuccess(
            self::sslRequest(false, true)->sslVerify(self::sslCert('ca.pem'), false)
        );

        // 服务端开启双向验证，未启用域名校验
        self::assertSslSuccess(
            self::sslRequest(true, '/check')
        );

        // 不提供 cert -> fail
        self::assertSslFailed(
            self::sslRequest()
        );

        // 使用合并的 PEM -> ok
        self::assertSslSuccess(
            self::sslRequest()->sslCert(self::sslCert('client.pem'))
        );

        // 使用独立的证书 密钥 -> ok
        self::assertSslSuccess(
            self::sslRequest()->sslCert(self::sslCert('client.crt'), self::sslCert('client.key'))
        );

        // 使用需要密码的 PEM + 不提供密码 -> fail
        self::assertSslFailed(
            self::sslRequest()->sslCert(self::sslCert('client_sec.pem'))
        );

        // 使用需要密码的 PEM + 错误密码 -> fail
        self::assertSslFailed(
            self::sslRequest()->sslCert(self::sslCert('client_sec.pem'), null, '666')
        );

        // 使用需要密码的 PEM + 正确密码 -> ok
        self::assertSslSuccess(
            self::sslRequest()->sslCert(self::sslCert('client_sec.pem'), null, '123456')
        );

        // 使用独立证书 需要密码的密钥 + 不提供密码 -> fail
        self::assertSslFailed(
            self::sslRequest()->sslCert(self::sslCert('client_sec.crt'), self::sslCert('client_sec.key'))
        );

        // 使用独立证书 需要密码的密钥 + 错误密码 -> fail
        self::assertSslFailed(
            self::sslRequest()->sslCert(self::sslCert('client_sec.crt'), self::sslCert('client_sec.key'), '666')
        );

        // 使用独立证书 需要密码的密钥 + 正确密码 -> ok
        self::assertSslSuccess(
            self::sslRequest()->sslCert(self::sslCert('client_sec.crt'), self::sslCert('client_sec.key'), '123456')
        );

        // 此时服务端还未开启域名校验, 使用域名不匹配的客户端证书 -> ok
        self::assertSslSuccess(
            self::sslRequest(true, '/check2')->sslCert(self::sslCert('mismatch.pem'))
        );

        // 服务端已经开启域名校验, 再次测试域名不匹配的证书 -> fail
        self::assertSslFailed(
            self::sslRequest()->sslCert(self::sslCert('mismatch.pem'))
        );

        // 使用配套的客户端证书试一下 -> ok
        self::assertSslSuccess(
            self::sslRequest()->sslCert(self::sslCert('client.pem'))
        );
    }

    private static function sslRequest($verify = true, $extra = '')
    {
        $domain = $path = '';
        if ($extra === true) {
            $domain = '2';
        } else {
            $path = $extra;
        }
        $port = static::$sslServerPort;
        $req = Curl::request('https://test'.$domain.'.com:'.$port.$path)
            ->hostResolver(['test'.$domain.'.com:'.$port.':127.0.0.1']);
        if ($verify) {
            $req->sslVerify(self::sslCert('ca.pem'));
        }
        return $req;
    }

    private static function sslCert($name)
    {
        return __DIR__.'/Server/certs/'.$name.(static::$sslCertTmp ? '.tmp' : '');
    }

    private static function assertSslFailed(Request $request)
    {
        $res = $request->send();
        self::assertNotNull($res->error);
    }

    private static function assertSslSuccess(Request $request)
    {
        $res = $request->send();
        self::assertEquals(200, $res->code);
        self::assertEquals('Hello World!', $res->content());
    }

    public function testInternetRequest()
    {
        // 未连接互联网 不测试
        $connected = @fsockopen("www.baidu.com", 80);
        if (!$connected){
            static::markTestIncomplete('no internet, skip test');
            return;
        }
        fclose($connected);

        // 测试下请求实际 https url
        $res = Curl::request('https://www.baidu.com')->send();
        self::assertNull($res->error);
        self::assertEquals(200, $res->code);

        // 使用真实代理 ip 测试代理功能;
        // 可以使用本机 VPN 测试试, 比如常见的 shadowsocks
        // 但这样不能(方便)测试使用 用户认证/证书认证 的代理设置
        // 如果有必要, 可使用如 Squid 之类的软件创建一个本地的 proxy server 进行测试
        $port = null;
        if ($connected = @fsockopen("127.0.0.1", 1080)) {
            $port = 1080;
            fclose($connected);
        } elseif ($connected = @fsockopen("127.0.0.1", 8080)) {
            $port = 8080;
            fclose($connected);
        } elseif ($connected = @fsockopen("127.0.0.1", 7890)) {
            $port = 7890;
            fclose($connected);
        }
        if (!$port){
            static::markTestIncomplete('test proxy port failed, skip test');
            return;
        }
        // 仅测试下 http 和 socks5 这两种常见代理,  https 代理很少用到, 并且 php curl 也是在 php7.3 之后才支持
        $res = Curl::request('https://www.baidu.com')->proxy('http://127.0.0.1:'.$port)->send();
        $res2 = Curl::request('https://www.baidu.com')->proxy('socks5://127.0.0.1:'.$port)->send();
        if (200 !== $res->code) {
            static::markTestIncomplete('test http proxy failed, make sure the proxy support http');
        }
        if (200 !== $res2->code) {
            static::markTestIncomplete('test socks5 proxy failed, make sure the proxy support socks5');
        }
    }
}

class PHPUNIT_ChunkedStream extends Stream {
    public function size()
    {
        return null;
    }
}
