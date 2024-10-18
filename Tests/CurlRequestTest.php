<?php

use Tanbolt\Curl\Request;
use Tanbolt\Curl\Response;
use Tanbolt\Curl\UserAgent;
use PHPUnit\Framework\TestCase;
use Tanbolt\Curl\TransportInterface;
use Tanbolt\Curl\Exception\RequestException;
use Tanbolt\Curl\Exception\UserCanceledException;

class CurlRequestTest extends TestCase
{
    public function testProperty()
    {
        $req = new Request();

        // name
        self::assertNull($req->name);
        self::assertSame($req, $req->name('foo'));
        self::assertEquals('foo', $req->name);

        // tcpDelay
        self::assertFalse($req->tcpDelay);
        self::assertSame($req, $req->tcpDelay());
        self::assertTrue($req->tcpDelay);
        self::assertSame($req, $req->tcpDelay(false));
        self::assertFalse($req->tcpDelay);

        // timeout
        self::assertSame($req, $req->timeout(2));
        self::assertEquals(2, $req->timeout);
        self::assertSame($req, $req->timeout(10.8));
        self::assertEquals(10.8, $req->timeout);

        // tryTime
        self::assertEquals(0, $req->tryTime);
        self::assertSame($req, $req->tryTime(2));
        self::assertEquals(2, $req->tryTime);
        self::assertSame($req, $req->tryTime(-2));
        self::assertEquals(0, $req->tryTime);

        // charset
        self::assertNull($req->charset);
        self::assertSame($req, $req->charset('utf-8'));
        self::assertEquals('utf-8', $req->charset);
        self::assertSame($req, $req->charset(null));
        self::assertNull($req->charset);

        // useEncoding
        self::assertFalse($req->useEncoding);
        self::assertSame($req, $req->useEncoding());
        self::assertTrue($req->useEncoding);
        self::assertSame($req, $req->useEncoding(false));
        self::assertFalse($req->useEncoding);

        // auth
        self::assertNull($req->auth);
        self::assertSame($req, $req->auth('name', 'value'));
        self::assertEquals(['name', 'value', null], $req->auth);
        self::assertSame($req, $req->auth('name', 'value', Request::AUTH_DIGEST));
        self::assertEquals(['name', 'value', Request::AUTH_DIGEST], $req->auth);
        self::assertSame($req, $req->auth(null));
        self::assertNull($req->auth);

        // alwaysAuth
        self::assertFalse($req->alwaysAuth);
        self::assertSame($req, $req->alwaysAuth());
        self::assertTrue($req->alwaysAuth);
        self::assertSame($req, $req->alwaysAuth(false));
        self::assertFalse($req->alwaysAuth);

        // autoRedirect
        self::assertTrue($req->autoRedirect);
        self::assertSame($req, $req->autoRedirect(false));
        self::assertFalse($req->autoRedirect);
        self::assertSame($req, $req->autoRedirect());
        self::assertTrue($req->autoRedirect);

        // maxRedirect
        self::assertEquals(5, $req->maxRedirect);
        self::assertSame($req, $req->maxRedirect(0));
        self::assertEquals(0, $req->maxRedirect);
        self::assertSame($req, $req->maxRedirect(2));
        self::assertEquals(2, $req->maxRedirect);
        self::assertSame($req, $req->maxRedirect(-1));
        self::assertEquals(0, $req->maxRedirect);

        // autoReferrer
        self::assertTrue($req->autoReferrer);
        self::assertSame($req, $req->autoReferrer(false));
        self::assertFalse($req->autoReferrer);
        self::assertSame($req, $req->autoReferrer());
        self::assertTrue($req->autoReferrer);

        // autoCookie
        self::assertTrue($req->autoCookie);
        self::assertSame($req, $req->autoCookie(false));
        self::assertFalse($req->autoCookie);
        self::assertSame($req, $req->autoCookie());
        self::assertTrue($req->autoCookie);

        // forceIp
        self::assertNull($req->forceIp);
        self::assertSame($req, $req->forceIpV4());
        self::assertTrue($req->forceIp);
        self::assertSame($req, $req->forceIpV6());
        self::assertFalse($req->forceIp);
        self::assertSame($req, $req->allowIpV46());
        self::assertNull($req->forceIp);

        // allowError
        self::assertFalse($req->allowError);
        self::assertSame($req, $req->allowError());
        self::assertTrue($req->allowError);
        self::assertSame($req, $req->allowError(false));
        self::assertFalse($req->allowError);

        // sslVerify
        self::assertTrue($req->sslVerify);
        self::assertTrue($req->sslVerifyHost);
        self::assertSame($req, $req->sslVerify(false, false));
        self::assertFalse($req->sslVerify);
        self::assertFalse($req->sslVerifyHost);
        self::assertSame($req, $req->sslVerify(__DIR__, true));
        self::assertEquals(__DIR__, $req->sslVerify);
        self::assertTrue($req->sslVerifyHost);
        self::assertSame($req, $req->sslVerify());
        self::assertTrue($req->sslVerify);
        self::assertTrue($req->sslVerifyHost);

        // sslCert
        self::assertNull($req->sslCert);
        self::assertSame($req, $req->sslCert('cert'));
        self::assertEquals(['cert', null, null], $req->sslCert);
        self::assertSame($req, $req->sslCert('cert', 'key'));
        self::assertEquals(['cert', 'key', null], $req->sslCert);
        self::assertSame($req, $req->sslCert('cert', 'key', 'pass'));
        self::assertEquals(['cert', 'key', 'pass'], $req->sslCert);
        self::assertSame($req, $req->sslCert(null));
        self::assertNull($req->sslCert);

        // sslCaptureInfo
        self::assertFalse($req->sslCaptureInfo);
        self::assertSame($req, $req->sslCaptureInfo());
        self::assertTrue($req->sslCaptureInfo);
        self::assertSame($req, $req->sslCaptureInfo(false));
        self::assertFalse($req->sslCaptureInfo);

        // proxy
        self::assertNull($req->proxy);
        self::assertSame($req, $req->proxy('http://127.0.0.1:1080'));
        self::assertEquals(['http://127.0.0.1:1080', null, null, null], $req->proxy);
        self::assertSame($req, $req->proxy('url', 'user', 'pass'));
        self::assertEquals(['url', 'user', 'pass', null], $req->proxy);
        self::assertSame($req, $req->proxy('url', 'user', 'pass', Request::AUTH_NTLM));
        self::assertEquals(['url', 'user', 'pass', Request::AUTH_NTLM], $req->proxy);
        self::assertSame($req, $req->proxy(null));
        self::assertNull($req->proxy);

        // useSystemProxy
        self::assertNull($req->useSystemProxy);
        self::assertSame($req, $req->useSystemProxy());
        self::assertTrue($req->useSystemProxy);
        self::assertSame($req, $req->useSystemProxy(false));
        self::assertFalse($req->useSystemProxy);
        self::assertSame($req, $req->useSystemProxy(null));
        self::assertNull($req->useSystemProxy);

        // proxyVerify
        self::assertFalse($req->proxyVerify);
        self::assertFalse($req->proxyVerifyHost);
        self::assertSame($req, $req->proxyVerify(true, true));
        self::assertTrue($req->proxyVerify);
        self::assertTrue($req->proxyVerifyHost);
        self::assertSame($req, $req->proxyVerify(__DIR__, false));
        self::assertEquals(__DIR__, $req->proxyVerify);
        self::assertFalse($req->proxyVerifyHost);
        self::assertSame($req, $req->proxyVerify(false, false));
        self::assertFalse($req->proxyVerify);
        self::assertFalse($req->proxyVerifyHost);

        // proxyCert
        self::assertNull($req->proxyCert);
        self::assertSame($req, $req->proxyCert('cert'));
        self::assertEquals(['cert', null, null], $req->proxyCert);
        self::assertSame($req, $req->proxyCert('cert', 'key'));
        self::assertEquals(['cert', 'key', null], $req->proxyCert);
        self::assertSame($req, $req->proxyCert('cert', 'key', 'pass'));
        self::assertEquals(['cert', 'key', 'pass'], $req->proxyCert);
        self::assertSame($req, $req->proxyCert(null));
        self::assertNull($req->proxyCert);

        // proxyHeader
        self::assertEquals([], $req->proxyHeader);
        self::assertSame($req, $req->proxyHeader($header = ['Proxy-Foo: foo', 'Proxy-Bar: bar']));
        self::assertEquals($header, $req->proxyHeader);
        self::assertSame($req, $req->proxyHeader([]));
        self::assertEquals([], $req->proxyHeader);

        // hostResolver
        self::assertEquals([], $req->hostResolver);
        self::assertSame($req, $req->hostResolver($resolver = ["domain.com:80:127.0.0.1", "example.com:443:192.168.1.2"]));
        self::assertEquals($resolver, $req->hostResolver);
        self::assertSame($req, $req->hostResolver([]));
        self::assertEquals([], $req->hostResolver);

        // method
        self::assertNull($req->method);
        self::assertSame($req, $req->method('GET'));
        self::assertEquals('GET', $req->method);
        self::assertSame($req, $req->method('put'));
        self::assertEquals('PUT', $req->method);
        self::assertSame($req, $req->method(null));
        self::assertNull($req->method);

        // version
        self::assertEquals(0, $req->version);
        self::assertSame($req, $req->version(2));
        self::assertEquals(2, $req->version);
        self::assertSame($req, $req->version(1.1));
        self::assertEquals(1.1, $req->version);
        self::assertSame($req, $req->version(0));
        self::assertEquals(0, $req->version);
    }

    public function testDebugProperty()
    {
        $req = new Request();
        self::assertFalse($req->debug);
        self::assertSame($req, $req->debug(true));
        self::assertTrue($req->debug);
        self::assertSame($req, $req->debug(__DIR__));
        self::assertEquals(__DIR__, $req->debug);

        $fp = fopen('php://memory', 'r+');
        self::assertSame($req, $req->debug($fp));
        self::assertEquals($fp, $req->debug);
        fclose($fp);

        self::assertSame($req, $req->debug(false));
        self::assertFalse($req->debug);
    }

    public function testUrlProperty()
    {
        $req = new Request();
        self::assertNull($req->url);
        self::assertNull($req->host);
        self::assertNull($req->user);
        self::assertNull($req->pass);
        self::assertEquals('/', $req->urlPath);
        self::assertNull($req->urlQuery);
        self::assertEquals(80, $req->port);
        self::assertEquals('http', $req->scheme);

        self::assertSame($req, $req->url($url = 'https://foo.com/path'));
        self::assertEquals($url, $req->url);
        self::assertEquals('foo.com', $req->host);
        self::assertEquals('/path', $req->urlPath);
        self::assertEquals(443, $req->port);
        self::assertEquals('https', $req->scheme);
        self::assertNull($req->user);
        self::assertNull($req->pass);
        self::assertNull($req->urlQuery);

        self::assertSame($req, $req->url($url = 'http://user:password@bar.com/sub/?a=a&b=b'));
        self::assertEquals($url, $req->url);
        self::assertEquals('bar.com', $req->host);
        self::assertEquals('/sub/', $req->urlPath);
        self::assertEquals(80, $req->port);
        self::assertEquals('http', $req->scheme);
        self::assertEquals('user', $req->user);
        self::assertEquals('password', $req->pass);
        self::assertEquals('a=a&b=b', $req->urlQuery);
    }

    public function testHeaders()
    {
        $req = new Request();
        self::assertEquals([], $req->headers);

        // headers
        self::assertSame($req, $req->headers([
            'content-type' => 'text/json',
            'Cache-control' => 'no-cache',
            'cookie' => ['foo=foo', 'bar=bar']
        ]));
        self::assertEquals([
            'Content-Type' => 'text/json',
            'Cache-Control' => 'no-cache',
            'Cookie' => ['foo=foo', 'bar=bar']
        ], $req->headers);

        // putHeader
        self::assertSame($req, $req->putHeader([
            'content-type' => 'text/plain',
            'connection' => 'keep-alive',
            'Expect' => '',
            'Cookie' => null,
        ]));
        self::assertEquals([
            'Content-Type' => 'text/plain',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'Expect' => '',
        ], $req->headers);

        self::assertSame($req, $req->putHeader('expect', null));
        self::assertEquals([
            'Content-Type' => 'text/plain',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
        ], $req->headers);

        self::assertSame($req, $req->putHeader('expect', '100-continue'));
        self::assertEquals([
            'Content-Type' => 'text/plain',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'Expect' => '100-continue',
        ], $req->headers);

        // getHeader
        self::assertEquals('no-cache', $req->getHeader('cache-control'));
        self::assertNull($req->getHeader('cookie'));
        self::assertEquals('a=a', $req->getHeader('cookie', 'a=a'));

        // removeHeader
        self::assertSame($req, $req->removeHeader('expect'));
        self::assertEquals([
            'Content-Type' => 'text/plain',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
        ], $req->headers);
        self::assertSame($req, $req->removeHeader(['Connection', 'cache-control']));
        self::assertEquals([
            'Content-Type' => 'text/plain',
        ], $req->headers);

        // userAgent
        self::assertNull($req->getHeader('User-Agent'));
        self::assertSame($req, $req->userAgent(UserAgent::ANDROID));
        self::assertEquals(UserAgent::ANDROID, $req->getHeader('User-Agent'));
        self::assertSame($req, $req->userAgent(null));
        self::assertNull($req->getHeader('User-Agent'));

        // referrer
        self::assertNull($req->getHeader('referer'));
        self::assertSame($req, $req->referrer($r = 'http://foo.com'));
        self::assertEquals($r, $req->getHeader('referer'));
        self::assertSame($req, $req->referrer(null));
        self::assertNull($req->getHeader('referer'));

        // cookie
        self::assertNull($req->getHeader('cookie'));
        self::assertSame($req, $req->cookie($r = 'foo=foo, bar=bar'));
        self::assertEquals($r, $req->getHeader('cookie'));
        self::assertSame($req, $req->cookie(null));
        self::assertNull($req->getHeader('cookie'));

        // forceMultipart
        self::assertSame($req, $req->forceMultipart());
        self::assertEquals('multipart/form-data', $req->getHeader('content-type'));
        self::assertSame($req, $req->forceMultipart('alternative'));
        self::assertEquals('multipart/alternative', $req->getHeader('content-type'));

        // disableExpect
        self::assertNull($req->getHeader('expect'));
        self::assertSame($req, $req->disableExpect());
        self::assertEquals('', $req->getHeader('expect'));

        //asAjax
        self::assertNull($req->getHeader('X-Requested-With'));
        self::assertSame($req, $req->asAjax());
        self::assertEquals('XMLHttpRequest', $req->getHeader('X-Requested-With'));

        // clearHeader
        self::assertSame($req, $req->clearHeader());
        self::assertEquals([], $req->headers);
    }

    public function testQueries()
    {
        $req = new Request();
        self::assertEquals([], $req->queries);

        // queries
        self::assertSame($req, $req->queries($r = [
            'foo' => 'foo',
            'bar' => 'bar',
            'biz' => ['v1', 'v2']
        ]));
        self::assertEquals($r, $req->queries);

        // putQuery
        self::assertSame($req, $req->putQuery([
            'foo' => 'foo1',
            'bar' => null,
            'biz' => '',
            'que' => 'que'
        ]));
        self::assertEquals([
            'foo' => 'foo1',
            'biz' => '',
            'que' => 'que'
        ], $req->queries);

        self::assertSame($req, $req->putQuery('biz', null));
        self::assertEquals([
            'foo' => 'foo1',
            'que' => 'que'
        ], $req->queries);

        self::assertSame($req, $req->putQuery('foo', 'foo'));
        self::assertSame($req, $req->putQuery('bar', 'bar'));
        self::assertEquals([
            'foo' => 'foo',
            'bar' => 'bar',
            'que' => 'que'
        ], $req->queries);

        // putRawQuery
        self::assertSame($req, $req->putRawQuery('foo=foo1&biz=biz&arr[]=v1&arr[]=v2'));
        self::assertEquals([
            'foo' => 'foo1',
            'bar' => 'bar',
            'que' => 'que',
            'biz' => 'biz',
            'arr' => ['v1', 'v2'],
        ], $req->queries);

        // getQuery
        self::assertEquals('foo1', $req->getQuery('foo'));
        self::assertNull($req->getQuery('none'));
        self::assertEquals('def', $req->getQuery('none', 'def'));

        // removeQuery
        self::assertSame($req, $req->removeQuery('foo'));
        self::assertEquals([
            'bar' => 'bar',
            'que' => 'que',
            'biz' => 'biz',
            'arr' => ['v1', 'v2'],
        ], $req->queries);

        self::assertSame($req, $req->removeQuery(['bar', 'que', 'none']));
        self::assertEquals([
            'biz' => 'biz',
            'arr' => ['v1', 'v2'],
        ], $req->queries);

        // clearQuery
        self::assertSame($req, $req->clearQuery());
        self::assertEquals([], $req->queries);
    }

    // 与 queries 测试流程完全相同
    public function testParams()
    {
        $req = new Request();
        self::assertEquals([], $req->params);

        // params
        self::assertSame($req, $req->params($r = [
            'foo' => 'foo',
            'bar' => 'bar',
            'biz' => ['v1', 'v2']
        ]));
        self::assertEquals($r, $req->params);

        // putParam
        self::assertSame($req, $req->putParam([
            'foo' => 'foo1',
            'bar' => null,
            'biz' => '',
            'que' => 'que'
        ]));
        self::assertEquals([
            'foo' => 'foo1',
            'biz' => '',
            'que' => 'que'
        ], $req->params);

        self::assertSame($req, $req->putParam('biz', null));
        self::assertEquals([
            'foo' => 'foo1',
            'que' => 'que'
        ], $req->params);

        self::assertSame($req, $req->putParam('foo', 'foo'));
        self::assertSame($req, $req->putParam('bar', 'bar'));
        self::assertEquals([
            'foo' => 'foo',
            'bar' => 'bar',
            'que' => 'que'
        ], $req->params);

        // putRawParam
        self::assertSame($req, $req->putRawParam('foo=foo1&biz=biz&arr[]=v1&arr[]=v2'));
        self::assertEquals([
            'foo' => 'foo1',
            'bar' => 'bar',
            'que' => 'que',
            'biz' => 'biz',
            'arr' => ['v1', 'v2'],
        ], $req->params);

        // getParam
        self::assertEquals('foo1', $req->getParam('foo'));
        self::assertNull($req->getParam('none'));
        self::assertEquals('def', $req->getParam('none', 'def'));

        // removeParam
        self::assertSame($req, $req->removeParam('foo'));
        self::assertEquals([
            'bar' => 'bar',
            'que' => 'que',
            'biz' => 'biz',
            'arr' => ['v1', 'v2'],
        ], $req->params);

        self::assertSame($req, $req->removeParam(['bar', 'que', 'none']));
        self::assertEquals([
            'biz' => 'biz',
            'arr' => ['v1', 'v2'],
        ], $req->params);

        // clearParam
        self::assertSame($req, $req->clearParam());
        self::assertEquals([], $req->params);
    }

    public function testFiles()
    {
        $req = new Request();
        self::assertEquals([], $req->files);
        $fp = fopen('php://memory', 'rb');

        // files
        self::assertSame($req, $req->files($r = [
            'foo' => 'file_foo',
            'bar' => $fp,
            'baz' => ['content'],
            'biz' => ['file' => 'file_biz', 'filename' => 'test.txt']
        ]));
        self::assertEquals($r, $req->files);

        // putFile
        self::assertSame($req, $req->putFile([
            'foo' => 'file_foo2',
            'baz' => null,
            'que' => ['que']
        ]));
        self::assertEquals([
            'foo' => 'file_foo2',
            'bar' => $fp,
            'que' => ['que'],
            'biz' => ['file' => 'file_biz', 'filename' => 'test.txt']
        ], $req->files);

        self::assertSame($req, $req->putFile('foo', null));
        self::assertEquals([
            'bar' => $fp,
            'que' => ['que'],
            'biz' => ['file' => 'file_biz', 'filename' => 'test.txt']
        ], $req->files);

        self::assertSame($req, $req->putFile('foo', 'file_foo'));
        self::assertEquals([
            'foo' => 'file_foo',
            'bar' => $fp,
            'que' => ['que'],
            'biz' => ['file' => 'file_biz', 'filename' => 'test.txt']
        ], $req->files);

        self::assertSame($req, $req->putFile('baz', 'file_baz', 'baz.zip', 'application/zip'));
        self::assertEquals([
            'foo' => 'file_foo',
            'bar' => $fp,
            'que' => ['que'],
            'biz' => ['file' => 'file_biz', 'filename' => 'test.txt'],
            'baz' => ['file' => 'file_baz', 'filename' => 'baz.zip', 'mimetype' => 'application/zip']
        ], $req->files);

        // getFile
        self::assertEquals(['file' => 'file_biz', 'filename' => 'test.txt'], $req->getFile('biz'));
        self::assertEquals('file_foo', $req->getFile('foo'));
        self::assertNull($req->getFile('none'));
        self::assertEquals('def', $req->getFile('none', 'def'));

        // removeFile
        self::assertSame($req, $req->removeFile('foo'));
        self::assertEquals([
            'bar' => $fp,
            'que' => ['que'],
            'biz' => ['file' => 'file_biz', 'filename' => 'test.txt'],
            'baz' => ['file' => 'file_baz', 'filename' => 'baz.zip', 'mimetype' => 'application/zip']
        ], $req->files);

        self::assertSame($req, $req->removeFile(['baz', 'biz', 'none']));
        self::assertEquals([
            'bar' => $fp,
            'que' => ['que'],
        ], $req->files);

        // clearFile
        self::assertSame($req, $req->clearFile());
        self::assertEquals([], $req->files);

        fclose($fp);
    }

    public function testBody()
    {
        $req = new Request();
        self::assertNull($req->body);
        self::assertNull($req->mimeType);

        self::assertSame($req, $req->body($r = 'body'));
        self::assertEquals($r, $req->body);
        self::assertNull($req->mimeType);

        self::assertSame($req, $req->body($r = ['foo' => 'foo'], 'text/json'));
        self::assertEquals($r, $req->body);
        self::assertEquals('text/json', $req->mimeType);

        self::assertSame($req, $req->body(null));
        self::assertNull($req->body);
        self::assertNull($req->mimeType);
    }

    public function testSaveTo()
    {
        $req = new Request();
        self::assertNull($req->saveTo);
        self::assertTrue($req->makeDirAuto);

        self::assertSame($req, $req->saveTo($r = __DIR__));
        self::assertEquals($r, $req->saveTo);
        self::assertSame($req, $req->saveTo(null));
        self::assertNull($req->saveTo);

        self::assertSame($req, $req->makeDirAuto(false));
        self::assertFalse($req->makeDirAuto);
        self::assertSame($req, $req->makeDirAuto());
        self::assertTrue($req->makeDirAuto);
    }

    public function testSetCallback()
    {
        $req = new Request();
        $callable = function (){};

        // onResponse
        self::assertNull($req->onResponse);
        self::assertSame($req, $req->onResponse($callable));
        self::assertSame($callable, $req->onResponse);
        self::assertSame($req, $req->onResponse(null));
        self::assertNull($req->onResponse);

        // onHeaderLine
        self::assertNull($req->onHeaderLine);
        self::assertSame($req, $req->onHeaderLine($callable));
        self::assertSame($callable, $req->onHeaderLine);
        self::assertSame($req, $req->onHeaderLine(null));
        self::assertNull($req->onHeaderLine);

        // onHeader
        self::assertNull($req->onHeader);
        self::assertSame($req, $req->onHeader($callable));
        self::assertSame($callable, $req->onHeader);
        self::assertSame($req, $req->onHeader(null));
        self::assertNull($req->onHeader);

        // onRedirect
        self::assertNull($req->onRedirect);
        self::assertSame($req, $req->onRedirect($callable));
        self::assertSame($callable, $req->onRedirect);
        self::assertSame($req, $req->onRedirect(null));
        self::assertNull($req->onRedirect);

        // onUpload
        self::assertNull($req->onUpload);
        self::assertSame($req, $req->onUpload($callable));
        self::assertSame($callable, $req->onUpload);
        self::assertSame($req, $req->onUpload(null));
        self::assertNull($req->onUpload);

        // onDownload
        self::assertNull($req->onDownload);
        self::assertSame($req, $req->onDownload($callable));
        self::assertSame($callable, $req->onDownload);
        self::assertSame($req, $req->onDownload(null));
        self::assertNull($req->onDownload);

        // onError
        self::assertNull($req->onError);
        self::assertSame($req, $req->onError($callable));
        self::assertSame($callable, $req->onError);
        self::assertSame($req, $req->onError(null));
        self::assertNull($req->onError);

        // onComplete
        self::assertNull($req->onComplete);
        self::assertSame($req, $req->onComplete($callable));
        self::assertSame($callable, $req->onComplete);
        self::assertSame($req, $req->onComplete(null));
        self::assertNull($req->onComplete);
    }

    public function testReset()
    {
        $req = new Request($url = 'https://user:pass@foo.com/path');
        self::checkUrl($req, $url);

        self::assertSame($req, $req->reset());
        self::assertNull($req->url);
        self::assertNull($req->host);
        self::assertNull($req->user);
        self::assertNull($req->pass);
        self::assertEquals('/', $req->urlPath);
        self::assertEquals(80, $req->port);
        self::assertEquals('http', $req->scheme);

        $req->reset(['url' => $url]);
        self::checkUrl($req, $url);

        // 可以重置所有参数, 这里随便挑几个测试一下
        $call = function (){};
        $req->reset([
            'method' => 'post',
            'forceIp' => true,
            'auth' => 'user',
            'sslVerify' => [__DIR__, false],
            'onResponse' => $call
        ]);
        self::assertEquals('POST', $req->method);
        self::assertTrue($req->forceIp);
        self::assertEquals(['user', null, null], $req->auth);
        self::assertEquals(__DIR__, $req->sslVerify);
        self::assertFalse($req->sslVerifyHost);
        self::assertEquals($call, $req->onResponse);

        // 不可以直接设置的属性
        try {
            $req->reset(['sslVerifyHost' => true]);
            self::fail('It should throw RequestException when stop method called');
        } catch (RequestException $e) {
            self::assertTrue(true);
        }

        // 不支持的属性
        try {
            $req->reset(['noneProps' => true]);
            self::fail('It should throw RequestException when stop method called');
        } catch (RequestException $e) {
            self::assertTrue(true);
        }
    }

    private static function checkUrl(Request $req, $url)
    {
        self::assertEquals($url, $req->url);
        self::assertEquals('foo.com', $req->host);
        self::assertEquals('user', $req->user);
        self::assertEquals('pass', $req->pass);
        self::assertEquals('/path', $req->urlPath);
        self::assertEquals(443, $req->port);
        self::assertEquals('https', $req->scheme);
    }

    public function testStop()
    {
        try {
            $req = new Request();
            $req->stop();
            self::fail('It should throw RequestException when stop method called');
        } catch (UserCanceledException $e) {
            self::assertTrue(true);
        }
    }

    public function testSend()
    {
        $req = new Request();
        $driver = $req->send('PHPUNIT_CurlTestDriver');
        self::assertSame($driver->request, $req);

        $driver2 = new PHPUNIT_CurlTestDriver();
        $req->send($driver2);
        self::assertSame($driver2->request, $req);
    }

    public function testSetResponse()
    {
        $req = new Request();
        self::assertNull($req->response);

        $res = new Response();
        self::assertSame($req, $req->setResponse($res));
        self::assertSame($res, $req->response);

        self::assertSame($req, $req->setResponse(null));
        self::assertNull($req->response);
    }
}

class PHPUNIT_CurlTestDriver implements TransportInterface
{
    public $request;

    /**
     * @inheritDoc
     */
    public function fetch(Request $request)
    {
        $this->request = $request;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function fetchMulti(array $requests, $maxConnection = 0)
    {
    }

    /**
     * @inheritDoc
     */
    public function close()
    {
    }
}
