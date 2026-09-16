<?php

declare(strict_types=1);

namespace Lychee\log\driver;

/**
 * 文件日志驱动。
 *
 * 按日期切分日志文件，写入到配置指定的目录下。
 * 文件名格式：YYYY-MM-DD.log
 */
class File
{
    protected string $path;

    /**
     * @param array{path?: string} $config
     */
    public function __construct(array $config = [])
    {
        $this->path = rtrim($config['path'] ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lychee-log', DIRECTORY_SEPARATOR);
    }

    /**
     * 写入日志内容。
     *
     * @param string $content 已格式化的日志行
     */
    public function write(string $content): bool
    {
        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }

        $file = $this->path . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';

        return (bool) file_put_contents($file, $content, FILE_APPEND | LOCK_EX);
    }
}
