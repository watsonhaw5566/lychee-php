<?php

declare(strict_types=1);

namespace Lychee\session\driver;

use Lychee\session\SessionDriverInterface;

/**
 * 文件 Session 驱动。
 *
 * 将会话数据序列化后存储到指定目录下的文件中。
 */
class File implements SessionDriverInterface
{
    private readonly string $path;

    public function __construct(
        string $path,
        private readonly int $gcProbability = 1,
        private readonly int $gcDivisor = 100,
        private readonly int $expireMinutes = 120,
    ) {
        $path = trim($path);

        // 路径为空或为根目录时，通常是配置错误（把会话存储目录 path 误设为 cookie_path 的 "/"）。
        // 回退到系统临时目录，避免触碰 open_basedir 限制。
        if ($path === '' || $path === DIRECTORY_SEPARATOR) {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lychee-session';
        }

        $this->path = rtrim($path, DIRECTORY_SEPARATOR);

        if (!@is_dir($this->path)) {
            @mkdir($this->path, 0777, true);
        }
    }

    public function read(string $id): string
    {
        $file = $this->path . '/sess_' . $id;

        if (!is_file($file)) {
            return '';
        }

        $content = (string) file_get_contents($file);

        // 过期检查
        if ($this->expireMinutes > 0) {
            $expireAt = filemtime($file) + ($this->expireMinutes * 60);
            if (time() > $expireAt) {
                @unlink($file);

                return '';
            }
        }

        return $content;
    }

    public function write(string $id, string $data): bool
    {
        $file = $this->path . '/sess_' . $id;

        return file_put_contents($file, $data, LOCK_EX) !== false;
    }

    public function destroy(string $id): bool
    {
        $file = $this->path . '/sess_' . $id;

        if (is_file($file)) {
            return @unlink($file);
        }

        return true;
    }

    /**
     * 回收过期会话文件。
     */
    public function gc(): void
    {
        if (mt_rand(1, $this->gcDivisor) > $this->gcProbability) {
            return;
        }

        $maxLifetime = $this->expireMinutes * 60;

        foreach (glob($this->path . '/sess_*') as $file) {
            if (is_file($file) && (time() - filemtime($file)) > $maxLifetime) {
                @unlink($file);
            }
        }
    }
}
