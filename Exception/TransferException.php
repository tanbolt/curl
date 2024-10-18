<?php
namespace Tanbolt\Curl\Exception;

use Exception;
use RuntimeException;

class TransferException extends RuntimeException implements CurlException
{
    /**
     * @var mixed
     */
    private $error = 0;

    /**
     * TransferException constructor.
     * @param string $message
     * @param int $code
     * @param Exception|null $previous
     */
    public function __construct($message = '', $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * 设置 请求失败 代码
     * @param mixed $error
     * @return $this
     */
    public function setError($error = 0)
    {
        $this->error = $error;
        return $this;
    }

    /**
     * 获取 请求失败 代码
     * @return mixed
     */
    public function error()
    {
        return $this->error;
    }
}
