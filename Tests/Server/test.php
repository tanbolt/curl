<?php
require __DIR__.'/SimpleTempServer.php';

class Test
{
    use SimpleTempServer;

    public static function start()
    {
        return static::startServer();
    }
}

$a = Test::start();
var_dump($a);