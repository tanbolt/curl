<?php
require_once __DIR__.'/CurlTransportCase.inc';

class LibCurlTest extends CurlTransportCase
{
    public static function driver()
    {
        return 'curl';
    }
}
