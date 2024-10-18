<?php
set_time_limit(0);

$err = '';
$errno = 0;
$context = stream_context_create();
$extension = '-s' === $argv[1] ? '.tmp' : '';
stream_context_set_option($context, 'ssl', 'cafile', __DIR__ . '/certs/ca.pem'.$extension);
stream_context_set_option($context, 'ssl', 'local_cert', __DIR__.'/certs/server.pem'.$extension);
$server = stream_socket_server('tls://127.0.0.1:'.$argv[2], $errno, $err, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $context);
if (!$server) {
    fwrite(STDOUT, 'failed');
    exit(-1);
}

fwrite(STDOUT, 'success_'.getmypid());
$serverId = (int) $server;
stream_set_blocking($server, 0);
stream_socket_enable_crypto($server, false);

$cryptoType = STREAM_CRYPTO_METHOD_SSLv2_SERVER | STREAM_CRYPTO_METHOD_SSLv23_SERVER;
$response = 'Hello World!';
$response = "HTTP/1.1 200 OK\r\n".
    "Connection: close\r\n" .
    "Content-Length: ".strlen($response)."\r\n".
    "Content-Type: text/html;charset=utf-8\r\n\r\n" .
    $response;

$read = [];
$clients = [];
$close = false;
while (!$close) {
    $write = [];
    $error = null;
    $read[$serverId] = $server;
    $ret = stream_select($read, $write, $error, 5);
    if (!$ret) {
        usleep(10);
        continue;
    }
    if (!$read) {
        continue;
    }
    foreach ($read as $socket) {
        $fd = (int) $socket;
        // 有客户端进来了
        if ($fd === $serverId) {
            $client = @stream_socket_accept($socket);
            if (!$client) {
                continue;
            }
            stream_set_blocking($client, 0);
            $clientId = (int) $client;
            $read[$clientId] = $client;
            continue;
        }
        // 验证连接 ssl
        if (!isset($clients[$fd])) {
            $check = @stream_socket_enable_crypto($socket, true, $cryptoType);
            if (true === $check) {
                $clients[$fd] = 1;
            } else {
                if (false === $check) {
                    @fclose($socket);
                    unset($read[$fd], $clients[$fd]);
                }
                continue;
            }
        }
        // 客户端已关闭
        $buffer = is_resource($socket) ? fread($socket, 65535) : false;
        if (false === $buffer || feof($socket)) {
            @fclose($socket);
            unset($read[$fd], $read[$fd]);
            continue;
        }
        // 接受客户端消息, 这里仅为测试 SSL, 简单起见, 只处理 GET 请求
        if (substr($buffer, -4) === "\r\n\r\n") {
            // 返回响应
            fwrite($socket, $response);

            // 特殊 path 处理: 双向认证, 校验域名
            preg_match('/GET ([^ ]+)/', $buffer, $match);
            if ($match[1] === '/check') {
                stream_context_set_option($context, 'ssl', 'verify_peer', true);
            } elseif ($match[1] === '/check2') {
                stream_context_set_option($context, 'ssl', 'peer_name', 'test.com');
                stream_context_set_option($context, 'ssl', 'verify_peer_name', true);
            }
        }
    }
}


