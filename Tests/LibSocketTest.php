<?php
require_once __DIR__.'/CurlTransportCase.inc';

class LibSocketTest extends CurlTransportCase
{
    public static function driver()
    {
        return 'socket';
    }
}
