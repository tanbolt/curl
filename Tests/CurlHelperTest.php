<?php

use Tanbolt\Curl\Helper;
use PHPUnit\Framework\TestCase;

/**
 * Class CurlHelperTest
 * 测试部分 Helper 助手函数
 */
class CurlHelperTest extends TestCase
{
    public function testSetResponseCookie()
    {
        $cookie = [];
        $except = [];

        // 域名不匹配 或 格式错误
        self::assertNull(Helper::setResponseCookie(
            'foo=foo; domain=bar.com', 'foo.com', $cookie
        ));
        self::assertEquals($except, $cookie);

        self::assertNull(Helper::setResponseCookie(
            'error; foo=foo; domain=bar.com', 'foo.com', $cookie
        ));
        self::assertEquals($except, $cookie);

        // 无 sub + 无 path
        $except[] = $cake = [
            'name' => 'foo',
            'value' => 'foo',
            'domain' => 'foo.com',
            'sub' => false,
            'path' => '/',
            'expires' => 0,
            'secure' => false
        ];
        self::assertEquals($cake, Helper::setResponseCookie(
            'foo=foo', 'foo.com', $cookie
        ));
        self::assertEquals($except, $cookie);

        // 有 sub + 无 path
        $except[] = $cake = [
            'name' => 'foo',
            'value' => 'foo',
            'domain' => 'foo.com',
            'sub' => true,
            'path' => '/',
            'expires' => 0,
            'secure' => false
        ];
        self::assertEquals($cake, Helper::setResponseCookie(
            'foo=foo; domain=foo.com', 'foo.com', $cookie
        ));
        self::assertEquals($except, $cookie);

        // 有 sub + 有 path
        $except[] = $cake = [
            'name' => 'foo',
            'value' => 'foo',
            'domain' => 'foo.com',
            'sub' => true,
            'path' => '/test',
            'expires' => 0,
            'secure' => false
        ];
        self::assertEquals($cake, Helper::setResponseCookie(
            'foo=foo; domain=foo.com; path=/test', 'foo.com', $cookie
        ));
        self::assertEquals($except, $cookie);

        // 有 sub + 有 path (再来一个)
        $except[] = $cake = [
            'name' => 'foo',
            'value' => 'foo2',
            'domain' => 'foo.com',
            'sub' => true,
            'path' => '/test',
            'expires' => 0,
            'secure' => false
        ];
        self::assertEquals($cake, Helper::setResponseCookie(
            'foo=foo2; domain=foo.com; path=/test', 'foo.com', $cookie
        ));
        self::assertEquals($except, $cookie);

        // 有 sub + 有 path + secure
        $except[] = $cake = [
            'name' => 'foo',
            'value' => 'foo2',
            'domain' => 'bar.com',
            'sub' => true,
            'path' => '/test2',
            'expires' => 0,
            'secure' => true
        ];
        self::assertEquals($cake, Helper::setResponseCookie(
            'foo=foo2; domain=bar.com; path=/test2; Secure', 'bar.com', $cookie
        ));
        self::assertEquals($except, $cookie);

        // 有 sub + 有 path + expires
        $expires = time() + 3000;
        $except[] = $cake = [
            'name' => 'foo',
            'value' => 'foo2',
            'domain' => 'foo.com',
            'sub' => true,
            'path' => '/test2',
            'expires' => $expires,
            'secure' => false
        ];
        self::assertEquals($cake, Helper::setResponseCookie(
            'foo=foo2; domain=foo.com; path=/test2; expires='.gmdate(DATE_RFC7231, $expires).';',
            'foo.com', $cookie
        ));
        self::assertEquals($except, $cookie);

        // 有 sub + 有 path (再来一个) + expires (有 max-age 但值为 0, 仍使用 expires)
        $except[] = $cake = [
            'name' => 'foo',
            'value' => 'foo2',
            'domain' => 'foo.com',
            'sub' => true,
            'path' => '/test2',
            'expires' => $expires,
            'secure' => false
        ];
        self::assertEquals($cake, Helper::setResponseCookie(
            'foo=foo2; domain=foo.com; path=/test2; expires='.gmdate(DATE_RFC7231, $expires).'; max-age=0',
            'foo.com', $cookie
        ));
        self::assertEquals($except, $cookie);

        // 有 sub + 有 path + expires (有 max-age 且不为 0, 使用 max-age)
        $except[] = $cake = [
            'name' => 'foo',
            'value' => 'foo2',
            'domain' => 'foo.com',
            'sub' => true,
            'path' => '/test2',
            'expires' => time() + 300,
            'secure' => false
        ];
        self::assertEquals($cake, Helper::setResponseCookie(
            'foo=foo2; domain=foo.com; path=/test2; expires='.gmdate(DATE_RFC7231, $expires).'; max-age=300',
            'foo.com', $cookie
        ));
        self::assertEquals($except, $cookie);

        // 过期 cookie (无 sub + 无 path)
        $expires = time() - 300;
        $cake = [
            'name' => 'foo',
            'value' => 'foo',
            'domain' => 'foo.com',
            'sub' => false,
            'path' => '/',
            'expires' => $expires,
            'secure' => false
        ];
        self::assertEquals($cake, Helper::setResponseCookie(
            'foo=foo; expires='.gmdate(DATE_RFC7231, $expires).'', 'foo.com', $cookie
        ));
        unset($except[0]);
        $except = array_values($except);
        self::assertEquals($except, $cookie);

        // 过期 cookie (有 sub + 无 path)
        $cake = [
            'name' => 'foo',
            'value' => 'foo',
            'domain' => 'foo.com',
            'sub' => true,
            'path' => '/',
            'expires' => $expires,
            'secure' => false
        ];
        self::assertEquals($cake, Helper::setResponseCookie(
            'foo=foo; domain=foo.com; expires='.gmdate(DATE_RFC7231, $expires).'',
            'foo.com', $cookie
        ));
        unset($except[0]);
        $except = array_values($except);
        self::assertEquals($except, $cookie);

        // 过期 cookie (有 sub + 有 path)
        $cake = [
            'name' => 'foo',
            'value' => 'foo',
            'domain' => 'foo.com',
            'sub' => true,
            'path' => '/test',
            'expires' => $expires,
            'secure' => false
        ];
        self::assertEquals($cake, Helper::setResponseCookie(
            'foo=foo; domain=foo.com; path=/test; expires='.gmdate(DATE_RFC7231, $expires).'',
            'foo.com', $cookie
        ));
        unset($except[0], $except[1]);
        $except = array_values($except);
        self::assertEquals($except, $cookie);
    }


    public function testGetRequestCookie()
    {
        $cookies = [
            // 无 sub + 无 path
            [
                'name' => 'foo',
                'value' => 'foo',
                'domain' => 'foo.com',
                'sub' => false,
                'path' => '/',
                'expires' => 0,
                'secure' => false
            ],
            [
                'name' => 'bar',
                'value' => 'bar',
                'domain' => 'foo.com',
                'sub' => false,
                'path' => '/',
                'expires' => 0,
                'secure' => false
            ],
            // 无 sub + 无 path (bar.com)
            [
                'name' => 'bar',
                'value' => 'bar',
                'domain' => 'bar.com',
                'sub' => false,
                'path' => '/',
                'expires' => 0,
                'secure' => false
            ],
            // 无 sub + 无 path (expires)
            [
                'name' => 'foo',
                'value' => 'foo1',
                'domain' => 'foo.com',
                'sub' => false,
                'path' => '/',
                'expires' => time() - 10,
                'secure' => false
            ],
            // 无 sub + 无 path (secure)
            [
                'name' => 'foo',
                'value' => 'foo2',
                'domain' => 'foo.com',
                'sub' => false,
                'path' => '/',
                'expires' => 0,
                'secure' => true
            ],
            // 无 sub + 有 path
            [
                'name' => 'foo',
                'value' => 'foo3',
                'domain' => 'foo.com',
                'sub' => false,
                'path' => '/test',
                'expires' => 0,
                'secure' => false
            ],
            // 有 sub + 有 path
            [
                'name' => 'foo',
                'value' => 'foo4',
                'domain' => 'foo.com',
                'sub' => true,
                'path' => '/test',
                'expires' => 0,
                'secure' => false
            ],
            // 子 sub + 有 path
            [
                'name' => 'foo',
                'value' => 'foo5',
                'domain' => 'sub.foo.com',
                'sub' => true,
                'path' => '/test',
                'expires' => 0,
                'secure' => false
            ],
        ];
        self::assertEquals('foo=foo; bar=bar', Helper::getRequestCookie($cookies, 'foo.com', '/'));
        self::assertEquals('foo=foo2; bar=bar', Helper::getRequestCookie($cookies, 'foo.com', '/', true));
        self::assertEquals('foo=foo; bar=bar', Helper::getRequestCookie($cookies, 'foo.com', '/test'));
        self::assertEquals('foo=foo; bar=bar', Helper::getRequestCookie($cookies, 'foo.com', '/foo'));
        self::assertEquals('foo=foo5', Helper::getRequestCookie($cookies, 'sub.foo.com', '/test'));
        self::assertEquals('', Helper::getRequestCookie($cookies, 'sub.foo.com', '/'));

        $cookies = array_reverse($cookies);
        self::assertEquals('', Helper::getRequestCookie($cookies, 'sub.foo.com', '/'));
        self::assertEquals('foo=foo4', Helper::getRequestCookie($cookies, 'sub.foo.com', '/test'));
        self::assertEquals('bar=bar; foo=foo', Helper::getRequestCookie($cookies, 'foo.com', '/'));
    }

}
