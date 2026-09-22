<?php

declare(strict_types=1);

namespace think;

use RuntimeException;
use SplFileInfo;

/**
 * 文件对象。
 *
 * think-validate 的 file/image/fileExt/fileMime/fileSize 等规则
 * 要求被校验值为 think\File 实例。本类基于 SplFileInfo 实现，
 * 提供校验器依赖的 getMime()、extension() 等方法。
 */
class File extends SplFileInfo
{
    public function __construct(string $path, bool $checkPath = true)
    {
        if ($checkPath && !is_file($path)) {
            throw new RuntimeException(sprintf('The file "%s" does not exist', $path));
        }

        parent::__construct($path);
    }

    /**
     * 获取文件的真实 MIME 类型（基于文件内容检测）。
     */
    public function getMime(): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo === false ? false : finfo_file($finfo, $this->getPathname());

        if ($finfo !== false) {
            finfo_close($finfo);
        }

        return $mime !== false ? $mime : 'application/octet-stream';
    }

    /**
     * 获取文件扩展名（小写，不含点）。
     */
    public function extension(): string
    {
        return strtolower($this->getExtension());
    }
}
