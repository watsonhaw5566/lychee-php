<?php

declare(strict_types=1);

namespace Lychee\http;

/**
 * 上传文件信息封装。
 *
 * 仅提供上传文件的元信息与内容读取；文件的存储由 filesystem 模块负责。
 */
class UploadedFile
{
    public function __construct(
        private readonly string $name,
        private readonly string $mimeType,
        private readonly string $tmpName,
        private readonly int $error,
        private readonly int $size,
    ) {
    }

    /**
     * 从 $_FILES 的单条记录创建实例。
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     */
    public static function create(array $file): self
    {
        return new self(
            name: $file['name'],
            mimeType: $file['type'],
            tmpName: $file['tmp_name'],
            error: $file['error'],
            size: $file['size'],
        );
    }

    /** 原始文件名（客户端上传时的文件名）。 */
    public function getOriginalName(): string
    {
        return $this->name;
    }

    /** 文件扩展名（不含点）。 */
    public function extension(): string
    {
        return strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
    }

    /** 文件大小（字节）。 */
    public function getSize(): int
    {
        return $this->size;
    }

    /** MIME 类型。 */
    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    /** 服务器临时文件路径。 */
    public function getTempName(): string
    {
        return $this->tmpName;
    }

    /** 上传错误码（UPLOAD_ERR_OK 等）。 */
    public function getError(): int
    {
        return $this->error;
    }

    /** 上传是否成功。 */
    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_uploaded_file($this->tmpName);
    }

    /**
     * 获取文件内容字符串。
     */
    public function getContent(): string
    {
        if (!$this->isValid()) {
            return '';
        }

        return (string) file_get_contents($this->tmpName);
    }

    /**
     * 获取文件流资源。
     *
     * @return resource|null
     */
    public function getStream()
    {
        if (!$this->isValid()) {
            return null;
        }

        $stream = fopen($this->tmpName, 'r');

        return $stream === false ? null : $stream;
    }
}
