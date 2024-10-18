<?php
namespace Tanbolt\Curl;

/**
 * Class StreamForm: 将 params 和 files 转为可用于 multipart/form-data post 的 StreamInterface
 * @package Tanbolt\Curl
 */
class StreamForm extends StreamBus
{
    /**
     * @var string
     */
    private $boundary;

    /**
     * @var Stream
     */
    private $endStream;

    /**
     * 创建 StreamForm 对象
     * @param null $boundary
     */
    public function __construct($boundary = null)
    {
        $this->boundary = $boundary ?: '--WebKitFromBoundary'.uniqid();
    }

    /**
     * Post 数据分隔符
     * @return string
     */
    public function boundary()
    {
        return $this->boundary;
    }

    /**
     * 添加一对 post 键值
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function addParam(string $key, $value)
    {
        return $this->addHeaders([
            'Content-Disposition' => "form-data; name=\"$key\""
        ])->addStream(
            static::createStringStream($value)
        )->addString("\r\n");
    }

    /**
     * 添加一个 Post file, 若 $file 是 resource 或 StreamInterface, 有两点需要注意
     * - 若在添加前指针已有偏移, 添加后会自动将指针设置到开始位置,
     * - 在添加后切勿对 $file 进行读写、定位等操作, 否则会导致内外不一致
     * @param string $key Post 字段名
     * @param StreamInterface|resource|string $file 文件路径 或 文件指针 resource 或 StreamInterface 对象
     * @param ?array $extra 可指定文件 filename mimetype
     * @return $this
     */
    public function addFile(string $key, $file, array $extra = null)
    {
        $stream = $file;
        $filename = null;
        if (!$stream instanceof StreamInterface) {
            $stream = new Stream($file);
            if ($filename = is_string($file) ? $file : $stream->meta('uri')) {
                $filename = basename($filename);
            }
        }
        return $this->addFileHeader($key, $extra, $filename)->addStream($stream)->addString("\r\n");
    }

    /**
     * 使用字符串（比如使用 file_get_contents 获取到的数据）添加一个 post file
     * @param string $key Post 字段名
     * @param string $content 文件内容
     * @param ?array $extra 可指定文件 filename mimetype
     * @return $this
     */
    public function addFileContent(string $key, string $content, array $extra = null)
    {
        $stream = static::createStringStream($content);
        return $this->addFileHeader($key, $extra)->addStream($stream)->addString("\r\n");
    }

    /**
     * 添加 Post File 的 header
     * @param string $key
     * @param ?array $extra
     * @param ?string $filename
     * @return $this
     */
    private function addFileHeader(string $key, array $extra = null, string $filename = null)
    {
        $name = $extra['filename'] ?? null;
        $extension = $name ? (pathinfo($name, PATHINFO_EXTENSION) ?: null) : null;
        if (!$extension && $filename) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: null;
            if (!$name) {
                $name = $filename;
            } elseif($extension) {
                $name .= '.'.$extension;
            }
        }
        $mimetype = $extra['mimetype'] ?? null;
        if (!$mimetype) {
            if ($extension) {
                $mimetype = Helper::getMimeType($extension);
            }
        } elseif (!$extension) {
            $extension = Helper::getExtension($mimetype);
            if ($extension) {
                if ($name) {
                    $name .= '.'.$extension;
                } else {
                    $name = uniqid().'.'.$extension;
                }
            }
        }
        if (!$name) {
            $name = uniqid();
        }
        if (!$mimetype) {
            $mimetype = 'application/octet-stream';
        }
        return $this->addHeaders([
            'Content-Disposition' => sprintf('form-data; name="%s"; filename="%s"', $key, $name),
            'Content-Type' => $mimetype
        ]);
    }

    /**
     * 新增一段 header string
     * @param $headers
     * @return $this
     */
    private function addHeaders($headers)
    {
        $str = '';
        foreach ($headers as $key => $value) {
            $str .= "$key: $value\r\n";
        }
        return $this->addString("--$this->boundary\r\n" . trim($str) . "\r\n\r\n");
    }

    /**
     * @inheritDoc
     */
    public function streams()
    {
        $streams = $this->streams;
        if (count($streams)) {
            if (!$this->endStream) {
                $this->endStream = static::createStringStream("--$this->boundary--\r\n")->rewind();
            }
            $streams[] = $this->endStream;
            return $streams;
        }
        return $streams;
    }

    /**
     * @inheritdoc
     */
    public function close()
    {
        $this->size = null;
        return parent::close();
    }
}
