<?php
/**
 * Trait Utils
 *
 * 可启动一个 http(s) server 服务端
 * 对于 http server，可使用 $serverDir / $serverRouter 指定服务端目录 和 目录下的 PHP 路由脚本
 */
trait SimpleTempServer
{
    /**
     * http server 根目录
     * @var string
     */
    protected static $serverDir = __DIR__;

    /**
     * http server 路由文件, (该路由必须输出 pid)
     * @var string
     */
    protected static $serverRouter = __DIR__.'/router.php';

    /**
     * http server 的进程 pid
     * @var int
     */
    protected static $serverPid;

    /**
     * http server 的 host
     * @var string
     */
    protected static $serverHost;

    /**
     * http server 的 port
     * @var string
     */
    protected static $serverPort;

    /**
     * http server url 前缀
     * @var string
     */
    protected static $urlPrefix;

    /**
     * 运行 http server 的 proc_open resource
     * @var resource
     */
    protected static $procHandle;

    /**
     * 运行 http server 的 proc_open pid
     * @var int
     */
    protected static $procPid;

    /**
     * openssl 创建证书所需的配置文件
     * @var string
     */
    private static $config = __DIR__. DIRECTORY_SEPARATOR . 'openssl.cnf';

    /**
     * https server 的进程 pid
     * @var int
     */
    protected static $sslServerPid;

    /**
     * https server 的 port
     * @var string
     */
    protected static $sslServerPort;

    /**
     * https server 是否使用了临时证书
     * @var bool
     */
    protected static $sslCertTmp;

    /**
     * 运行 https server 的 proc_open resource
     * @var resource
     */
    protected static $sslProcHandle;

    /**
     * 运行 https server 的 proc_open pid
     * @var int
     */
    protected static $sslProcPid;

    /**
     * 启动 HTTP server
     * 多次启动(未成对调用 stopServer)会产生僵尸 php 进程, 测试用, 就不那么讲究了, 使用时注意下
     * @return bool
     */
    public static function startServer()
    {
        $host = '127.0.0.1';
        $port = static::getAvailablePort();
        $cmd = [PHP_BINARY, '-n', '-S', $host.':'.$port, '-t', static::$serverDir, static::$serverRouter];
        $command = join(' ', $cmd);
        $descriptor = [STDIN, STDOUT, STDOUT];
        $handle = proc_open($command, $descriptor, $pipes, static::$serverDir, null, ["suppress_errors" => true]);
        if (!$handle || !($pid = static::getProcPid($handle))) {
            return false;
        }
        // 判断 php server 是否启动成功的正确方法应该是 从 STDOUT 中提取字符串判断
        // 但是 php7 在同一个进程内无法提取(暂未找到合适的方法), 所以这里停顿2秒, 一般足够 php server 启动成功了
        sleep(2);
        $urlPrefix = 'http://'.$host.':'.$port;
        $test = @file_get_contents($urlPrefix);
        if ($test && false !== strpos($test, '_')) {
            list($str, $serverPid) = explode('_', $test);
            if ('hello' === $str) {
                static::$serverHost = $host;
                static::$serverPort = $port;
                static::$serverPid = $serverPid;
                static::$urlPrefix = $urlPrefix;
                static::$procHandle = $handle;
                static::$procPid = $pid;
                return true;
            }
        }
        proc_terminate($handle);
        return false;
    }

    /**
     * 获取 proc_open 打开的进程 id
     * @param resource $handle
     * @return false|mixed
     */
    private static function getProcPid($handle)
    {
        $check = 0;
        $pid = false;
        while (true) {
            $status = proc_get_status($handle);
            if (empty($status['running']) || empty($status['pid'])) {
                usleep(50000);
            } else {
                $pid = $status['pid'];
                break;
            }
            if (++$check > 10) {
                break;
            }
        }
        if (!$pid) {
            proc_terminate($handle);
            return false;
        }
        return $pid;
    }

    /**
     * 停止 HTTP server
     */
    public static function stopServer()
    {
        if (static::$procHandle) {
            if (proc_terminate(static::$procHandle)) {
                static::$procHandle = null;
            } else {
                print "close proc_open process failed[pid:".static::$procPid."]\n";
            }
        }
        if (static::$serverPid) {
            if (static::killPid(static::$serverPid)) {
                static::$serverPid = null;
            } else {
                $test = @file_get_contents(static::$urlPrefix);
                if ($test) {
                    print "stop php server failed [pid:".static::$serverPid."]\n";
                }
            }
        }
    }

    /**
     * 获得一个可用端口
     * 在 windows 下，无论是 http 还是 https server 在使用已被占用的端口时, 仍然会启动成功。
     * 端口不是独占的，这可能给测试带来问题，所以可提前探测端口
     * @param int $port
     * @return int|mixed
     */
    public static function getAvailablePort($port = 9020)
    {
        $err = '';
        $errno = 0;
        $connection = @fsockopen('127.0.0.1', $port, $errno, $err, 1);
        if (is_resource($connection)) {
            return static::getAvailablePort($port + 1);
        }
        return $port;
    }

    /**
     * kill pid
     * @param $pid
     * @return bool
     */
    public static function killPid($pid)
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            $command = 'taskkill /F /T /PID ' . $pid;
            if (function_exists('exec')) {
                exec($command . ' 2>&1', $output, $code);
                return !$code;
            }
        } else {
            if (function_exists('posix_kill')) {
                return @posix_kill($pid, 9);
            }
            $command = 'kill -9 '.$pid;
        }
        $descriptor = [STDIN, ['pipe', 'w'], ['pipe', 'w']];
        $handler = proc_open($command, $descriptor, $pipes);
        do {
            $status = proc_get_status($handler);
            $running = !empty($status['running']);
        } while($running);
        $code = $status['exitcode'] ?? 128;
        return !$code;
    }

    /**
     * 生成 KEY
     * @param int|null $keyLength
     * @return resource|false
     */
    public static function createKey(int $keyLength = null)
    {
        $keyLength = $keyLength ?: 2048;
        return openssl_pkey_new([
            'encrypt_key' => false,
            'config' => self::$config,
            'private_key_bits' => $keyLength,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
    }

    /**
     * 生成 CA
     * @param ?array $ca
     * @return resource|false
     */
    public static function createCa(array &$ca = null)
    {
        // 生成: key
        if (!$key = self::createKey()) {
            return false;
        }
        // 生成: csr
        $dn = [
            'countryName' => 'CN',
            'stateOrProvinceName' => 'HaiNan',
            'localityName' => 'WenChang',
            'organizationName' => 'Tanbolt',
            'commonName' => 'CA for Tanbolt Tests',
        ];
        $csr = openssl_csr_new($dn, $key, [
            'config' => self::$config,
            'x509_extensions' => 'v3_ca',
        ]);
        if (!$csr) {
            return false;
        }
        // 生成: crt
        $caCert = null;
        if ($cert = openssl_csr_sign($csr, null, $key, 300, ['config' => self::$config])) {
            openssl_x509_export($cert, $caCert);
        }
        if ($caCert) {
            $ca = [$cert, $key];
        }
        return $caCert ?: false;
    }

    /**
     * 生成并保存一个 Cert 证书
     * @param array $ca
     * @param string $commonName
     * @param ?string $passphrase
     * @return array|false
     */
    public static function createCert(array $ca, string $commonName, string $passphrase = null)
    {
        // 生成: key
        if (!$key = self::createKey()) {
            return false;
        }
        // 生成: csr
        $dn = [
            'countryName' => 'BY',
            'stateOrProvinceName' => 'Minsk',
            'localityName' => 'Minsk',
            'organizationName' => 'Example Org',
        ];
        if (null !== $commonName) {
            $dn['commonName'] = $commonName;
        }
        $config = [
            'config' => self::$config,
            'x509_extensions' => 'usr_cert',
            'req_extensions' => 'v3_req',
        ];
        $csr = openssl_csr_new($dn, $key, $config);
        if (!$csr) {
            return false;
        }
        // 生成: cert
        $certText = $keyText = '';
        if ($cert = openssl_csr_sign($csr, $ca[0], $ca[1], 300, $config)) {
            openssl_x509_export($cert, $certText);
            openssl_pkey_export($key, $keyText, $passphrase, ['config' => self::$config]);
        }
        if ($certText && $keyText) {
            return [$certText, $keyText];
        }
        return false;
    }

    /**
     * 生成单元测试所需 certs
     * @param string $extension
     * @return bool
     */
    public static function makeTestCerts($extension = '.tmp')
    {
        // CA
        if (!$caCert = self::createCa($ca)) {
            return false;
        }
        $cache = ['ca.pem' => $caCert];

        // certs
        $tests = [
            'server', // 服务端用
            'client', // 客户端用
            'client_sec', // 客户端用(带密码)
            'mismatch', // 客户端用(域名不匹配)
        ];
        foreach ($tests as $name) {
            $passphrase = 'client_sec' === $name ? '123456' : null;
            $commonName = 'mismatch' === $name ? 'mismatch.com' : 'test.com';
            $cert = self::createCert($ca, $commonName, $passphrase);
            if (!$cert) {
                return false;
            }
            $cache[$name.'.pem'] = join(PHP_EOL, $cert);
            if ('client' === $name || 'client_sec' === $name) {
                $cache[$name.'.crt'] = $cert[0];
                $cache[$name.'.key'] = $cert[1];
            }
        }
        $dir = __DIR__.'/certs';
        foreach ($cache as $name => $content) {
            file_put_contents($dir.'/'.$name.$extension, $content);
        }
        return true;
    }

    /**
     * 启动 https server
     * @return bool
     */
    public static function startSSLServer()
    {
        $tmp = static::makeTestCerts() ? '-s' : '-d';
        $port = static::getAvailablePort(9030);
        $cmd = [PHP_BINARY, __DIR__.'/https.php', $tmp, $port];
        $command = join(' ', $cmd);
        $stdFile = __DIR__.'/https_socket';
        $descriptor = [STDIN, ['file', $stdFile, 'w'], STDERR];
        $handle = proc_open($command, $descriptor, $pipes, static::$serverDir, null, ["suppress_errors" => true]);
        if (!$handle || !($pid = static::getProcPid($handle))) {
            return false;
        }
        $result = 0;
        stream_set_blocking($stdHandle = fopen($stdFile, 'rb'), 0);
        while (0 === $result) {
            $write = [];
            $error = null;
            $read = [$stdHandle];
            $ret = stream_select($read, $write, $error, 5);
            if (!$ret) {
                usleep(10);
                continue;
            }
            foreach ($read as $socket) {
                $buffer = is_resource($socket) ? fread($socket, 65535) : false;
                if ($buffer) {
                    fclose($stdHandle);
                    $result = strpos($buffer, '_') ? (int) explode('_', $buffer)[1] : -1;
                    break;
                }
            }
        }
        if (-1 === $result) {
            return false;
        }
        static::$sslServerPid = $result;
        static::$sslServerPort = $port;
        static::$sslCertTmp = '-s' === $tmp;
        static::$sslProcHandle = $handle;
        static::$sslProcPid = $pid;
        return true;
    }

    /**
     * 停止 HTTPS server
     */
    public static function stopSSLServer()
    {
        if (static::$sslProcHandle) {
            if (proc_terminate(static::$sslProcHandle)) {
                static::$sslProcHandle = null;
            } else {
                print "close proc_open process failed[pid:".static::$sslProcPid."]\n";
            }
        }
        if (static::$sslServerPid) {
            if (static::killPid(static::$sslServerPid)) {
                static::$sslServerPid = null;
            } else {
                print "stop php server failed [pid:".static::$sslServerPid."]\n";
            }
        }
        if (static::$sslCertTmp) {
            foreach (glob(__DIR__."/certs/*.tmp") as $filename) {
                unlink($filename);
            }
            static::$sslCertTmp = null;
        }
        @unlink(__DIR__.'/https_socket');
    }
}
