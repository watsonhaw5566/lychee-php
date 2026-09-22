<?php

declare(strict_types=1);

namespace Lychee\http;

use think\File;

/**
 * 上传文件信息封装。
 *
 * 继承 think\File（基于 SplFileInfo），因此可直接被 think-validate
 * 的 file/image/fileExt/fileMime/fileSize 等规则校验。
 *
 * 文件的实际存储由 filesystem 模块负责。
 */
class UploadedFile extends File
{
    private bool $test;

    public function __construct(
        string  $tmpName,
        private readonly string $originalName,
        private readonly ?string $clientMimeType = null,
        private readonly int $uploadError = UPLOAD_ERR_OK,
        bool $test = false,
        private readonly ?int $reportedSize = null,
    ) {
        $this->test = $test;

        // 仅在上传成功时校验临时文件是否存在
        parent::__construct($tmpName, $this->uploadError === UPLOAD_ERR_OK);
    }

    /**
     * 从 $_FILES 的单条记录创建实例。
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     */
    public static function create(array $file, bool $test = false): self
    {
        return new self(
            tmpName: $file['tmp_name'],
            originalName: $file['name'],
            clientMimeType: $file['type'],
            uploadError: $file['error'],
            test: $test,
            reportedSize: $file['size'],
        );
    }

    /** 原始文件名（客户端上传时的文件名）。 */
    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    /** 客户端声明的 MIME 类型（不可信）。 */
    public function getOriginalMime(): string
    {
        return $this->clientMimeType ?? 'application/octet-stream';
    }

    /** 客户端声明的 MIME 类型（不可信）。 */
    public function getMimeType(): string
    {
        return $this->getOriginalMime();
    }

    /** 原始文件名的扩展名（小写，不含点）。 */
    public function getOriginalExtension(): string
    {
        return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
    }

    /**
     * 文件扩展名（取自原始文件名）。
     *
     * 临时文件本身没有扩展名，因此覆盖 think\File 的实现。
     */
    public function extension(): string
    {
        return $this->getOriginalExtension();
    }

    /**
     * 文件大小（字节）。
     *
     * 优先使用 $_FILES 上报的大小，避免文件已被移动后读取失败。
     */
    public function getSize(): int
    {
        return $this->reportedSize ?? parent::getSize();
    }

    /** 服务器临时文件路径。 */
    public function getTempName(): string
    {
        return $this->getPathname();
    }

    /** 上传错误码（UPLOAD_ERR_OK 等）。 */
    public function getError(): int
    {
        return $this->uploadError;
    }

    /**
     * 上传是否成功。
     *
     * test 模式（单元测试模拟上传）下跳过 is_uploaded_file 检查。
     */
    public function isValid(): bool
    {
        if ($this->uploadError !== UPLOAD_ERR_OK) {
            return false;
        }

        return $this->test || is_uploaded_file($this->getPathname());
    }

    /**
     * 获取文件内容字符串。
     */
    public function getContent(): string
    {
        if (!$this->isValid()) {
            return '';
        }

        return (string) file_get_contents($this->getPathname());
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

        $stream = fopen($this->getPathname(), 'r');

        return $stream === false ? null : $stream;
    }
}
