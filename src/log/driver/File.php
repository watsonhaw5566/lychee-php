<?php

declare(strict_types=1);

namespace Lychee\log\driver;

/**
 * 文件日志驱动。
 *
 * 按日期切分日志文件，写入到配置指定的目录下。
 * 文件名格式：YYYY-MM-DD.log
 *
 * 支持 max_files 配置：保留最近 N 天的日志文件，超过数量的旧文件在写入时自动清理。
 */
class File
{
    protected string $path;

    /** 保留的最大日志文件数量（天数），0 表示不限制 */
    protected int $maxFiles;

    /**
     * @param array{path?: string, max_files?: int} $config
     */
    public function __construct(array $config = [])
    {
        $this->path     = rtrim($config['path'] ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lychee-log', DIRECTORY_SEPARATOR);
        $this->maxFiles = max(0, (int) ($config['max_files'] ?? 0));
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

        $result = (bool) file_put_contents($file, $content, FILE_APPEND | LOCK_EX);

        // 写入后清理超过 max_files 数量的旧日志文件
        if ($this->maxFiles > 0) {
            $this->cleanupOldFiles();
        }

        return $result;
    }

    /**
     * 清理超过 max_files 数量的旧日志文件。
     *
     * 仅清理本目录下符合 YYYY-MM-DD.log 格式的文件，按文件名倒序保留最近 max_files 个。
     */
    protected function cleanupOldFiles(): void
    {
        $files = glob($this->path . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].log');

        if ($files === false || count($files) <= $this->maxFiles) {
            return;
        }

        // 按文件名降序排列（最新的在前）
        rsort($files);

        // 删除超出 max_files 数量的旧文件
        foreach (array_slice($files, $this->maxFiles) as $oldFile) {
            @unlink($oldFile);
        }
    }
}
