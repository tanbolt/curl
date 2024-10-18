<?php
namespace Tanbolt\Curl;

interface TransportInterface
{
    /**
     * 请求单个 Request
     * @param Request $request
     * @return Response
     */
    public function fetch(Request $request);

    /**
     * 并发请求多个 Request
     * @param Request[] $requests
     * @param int $maxConnection 并发请求时,同时打开的最大连接数
     * @return Response
     */
    public function fetchMulti(array $requests, int $maxConnection = 0);

    /**
     * 关闭所有 Request 请求
     * @return mixed
     */
    public function close();
}
