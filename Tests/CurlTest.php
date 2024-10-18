<?php

use Tanbolt\Curl\Curl;
use PHPUnit\Framework\TestCase;

/**
 * Class LibTransportCase
 * 测试 TransportInterface 通信驱动, 只需实现 driver() 方法即可
 */
class CurlTest extends TestCase
{

    public function testCurl()
    {
        $r1 = Curl::request();
        $r2 = Curl::request();
        $r3 = Curl::request();
        $r4 = Curl::request();

        $curl = new Curl();

        self::assertSame($curl, $curl->add($r1, $r2, [$r3, $r4]));
        $collection = $curl->collection();
        self::assertEquals([$r1, $r2, $r3, $r4], array_values($collection));

        self::assertSame($curl, $curl->remove($r1, [$r2, $r3]));
        $collection = $curl->collection();
        self::assertEquals([$r4], array_values($collection));


        self::assertSame($curl, $curl->clear());
        self::assertEquals([], $curl->collection());
    }

}
