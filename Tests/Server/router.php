<?php
$ac = $_GET['ac'] ?? null;


// https://www.php.net/manual/zh/features.http-auth.php
function http_digest_parse($txt)
{
    // protect against missing data
    $data = [];
    $needed_parts = ['nonce'=>1, 'nc'=>1, 'cnonce'=>1, 'qop'=>1, 'username'=>1, 'uri'=>1, 'response'=>1];
    $keys = implode('|', array_keys($needed_parts));
    preg_match_all('@(' . $keys . ')=(?:([\'"])([^\2]+?)\2|([^\s,]+))@', $txt, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $data[$m[1]] = $m[3] ?: $m[4];
        unset($needed_parts[$m[1]]);
    }
    return $needed_parts ? false : $data;
}


if ('basic' === $ac) {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Custom: Tanbolt', true);
    exit(json_encode(['rs' => 'success']));
}

if ('method' === $ac) {
    echo $_SERVER['REQUEST_METHOD'];
    exit();
}

if ('header' === $ac) {
    exit(json_encode(getallheaders()));
}

if ('timeout' === $ac) {
    $sleep = $_GET['sleep'] ?? 0;
    if ($sleep) {
        usleep($sleep);
    }
    exit('timeout');
}

if ('encoding' === $ac) {
    header('Content-Encoding: gzip');
    $encode = gzencode('encoding', 9);
    exit($encode);
}

if ('charset' === $ac) {
    $gbk = '';
    foreach ([214, 208, 206, 196] as $ord) {
        $gbk .= chr($ord);
    }
    $type = $_GET['type'] ?? null;
    if (!$type) {
        header('Content-Type: text/plain; charset=GBK', true);
        exit($gbk);
    }
    $charset = ini_get('default_charset');
    ini_set('default_charset', NULL);
    if ('none' === $type) {
        header('Content-Type: text/plain', true);
        echo $gbk;
    } else {
        $html = file_get_contents(__DIR__ . '/gbk.html');
        $html = str_replace('{body}', $gbk, $html);
        header('Content-Type: text/html', true);
        echo $html;
    }
    ini_set('default_charset', $charset);
    exit();
}

if ('cookie' === $ac) {
    $set = $_GET['set'] ?? null;
    if ('yes' === $set) {
        setcookie('sa', 'sa', time() + 1000, '/');
        setcookie('sb', 'sb', time() + 1000, '/path');
        setcookie('sc', 'sc');
        http_response_code(301);
        if (isset($_GET['rm'])) {
            header('Location: /?ac=cookie&set=rm');
        } else {
            header('Location: '.$_SERVER['SCRIPT_NAME'].'?ac=cookie');
        }
        exit();
    } elseif ('rm' === $set) {
        setcookie('sc', '', time() - 4000);
        http_response_code(301);
        header('Location: /?ac=cookie');
        exit();
    }
    exit(json_encode($_COOKIE));
}

if ('bauth' === $ac) {
    $user = $_SERVER['PHP_AUTH_USER'] ?? null;
    $pass = $_SERVER['PHP_AUTH_PW'] ?? null;
    if ('user' !== $user || 'pass' !== $pass) {
        header('WWW-Authenticate: Basic realm="My Realm"');
        header('HTTP/1.0 401 Unauthorized');
        exit('Unauthorized');
    }
    exit($user);
}

if ('auth' === $ac) {
    if (isset($_GET['go'])) {
        http_response_code(301);
        header('Location: '.$_GET['go'].'/?ac=auth');
        exit();
    }
    $user = 'user';
    $pass = 'pass';
    $realm = 'Restricted area';
    if (empty($_SERVER['PHP_AUTH_DIGEST'])) {
        header('HTTP/1.1 401 Unauthorized');
        header('WWW-Authenticate: Digest realm="'.$realm.'",qop="auth",nonce="'.uniqid().'",opaque="'.md5($realm).'"');
        exit('Unauthorized');
    }
    if (!($data = http_digest_parse($_SERVER['PHP_AUTH_DIGEST'])) || $data['username'] !== $user){
        exit('Wrong Credentials!');
    }
    $A1 = md5($user . ':' . $realm . ':' . $pass);
    $A2 = md5($_SERVER['REQUEST_METHOD'].':'.$data['uri']);
    $valid_response = md5($A1.':'.$data['nonce'].':'.$data['nc'].':'.$data['cnonce'].':'.$data['qop'].':'.$A2);
    if ($data['response'] != $valid_response) {
        exit('Wrong Credentials!');
    }
    exit($user);
}

if ('param' === $ac) {
    $json = [];
    foreach ([
        'get' => $_GET,
        'post' => $_POST,
        'file' => $_FILES
    ] as $key => $arr) {
        if (count($arr)) {
            $json[$key] = $arr;
        }
    }
    exit(json_encode($json));
}

// ?ac=redirect&codes=301-307-302
if ('redirect' === $ac) {
    $code = null;
    $step = 0;
    $lastStep = true;
    $codes = $_GET['codes'] ?? null;
    if ($codes) {
        $arr = explode('-', $codes);
        $step = $_GET['step'] ?? 0;
        if ($step < $count = count($arr)) {
            $code = $arr[$step];
            if ($step < $count - 1) {
                $lastStep = false;
            }
        }
    }
    if (null === $code) {
        $code = 301;
    } else {
        $code = (int) $code;
        if ($code < 300 || $code >= 400 || $code === 304) {
            http_response_code(400);
            exit();
        }
    }
    // 可测试 所有重定向时间加一起 timeout
    $location = $lastStep ? '?ac=body' : '?ac=redirect&codes='.$codes.'&step='.($step + 1);
    $sleep = $_GET['sleep'] ?? 0;
    if ($sleep) {
        usleep($sleep);
        if (!$lastStep) {
            $location .= '&sleep='.$sleep;
        }
    }
    http_response_code($code);
    header('Location: /'.$location);
    exit();
}

if ('body' === $ac) {
    $headers = getallheaders();
    header('Request-Method: '.$_SERVER['REQUEST_METHOD']);
    header('Request-Expect: '.($headers['Expect'] ?? ''));
    header('Request-Content-Type: '.($headers['Content-Type'] ?? ''));
    header('Request-Content-Length: '.($headers['Content-Length'] ?? ''));
    header('Request-Transfer-Encoding: '.($headers['Transfer-Encoding'] ?? ''));
    header('Request-Referer: '.($headers['Referer'] ?? ''));

    $body = 'body:'.file_get_contents('php://input');
    if (!isset($_GET['chunked'])) {
        exit($body);
    }
    header('Transfer-Encoding: chunked');
    $chunked = str_split ($body, 12);
    foreach ($chunked as $chunk) {
        echo dechex(strlen($chunk))."\r\n".$chunk."\r\n";
        flush();
    }
    echo "0\r\n\r\n";
    flush();
    exit();
}

if ('call' === $ac) {
    $end = $_GET['end'] ?? null;
    if ($end) {
        header('X-Call: end');
        $body = file_get_contents('php://input');
        $first = substr($body, 0, 10);
        $last = substr($body, 10);
        echo $first;
        usleep(100000);
        exit($last);
    }
    http_response_code(307);
    header('X-Redirect: call');
    header('Location: /?ac=call&end=1');
    exit();
}


echo 'hello_'.getmypid();