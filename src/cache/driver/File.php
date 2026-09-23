<?php

declare(strict_types=1);

namespace Lychee\cache\driver;

use DateInterval;
use DateTimeInterface;
use FilesystemIterator;
use InvalidArgumentException;
use Lychee\cache\Driver;
use Throwable;

/**
 * 文件缓存驱动。
 *
 * 键名经 md5 哈希后按前两位分目录存储，缓存文件头部记录相对 TTL，
 * 结合文件 mtime 判断过期；inc/dec 复用基类实现（读-改-写，非原子）。
 */
class File extends Driver
{
    /**
     * 配置参数。
     *
     * @var array<string, mixed>
     */
    protected array $options = [
        'expire'        => 0,
        'cache_subdir'  => true,
        'prefix'        => '',
        'path'          => '',
        'hash_type'     => 'md5',
        'data_compress' => false,
    ];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge($this->options, $options);

        if (!str_ends_with((string) $this->options['path'], DIRECTORY_SEPARATOR)) {
            $this->options['path'] .= DIRECTORY_SEPARATOR;
        }
    }

    /**
     * 获取变量的存储文件名。
     */
    public function getCacheKey(string $name): string
    {
        $name = hash((string) $this->options['hash_type'], $name);

        if ($this->options['cache_subdir']) {
            $name = substr($name, 0, 2) . DIRECTORY_SEPARATOR . substr($name, 2);
        }

        if ($this->options['prefix'] !== '') {
            $name = $this->options['prefix'] . DIRECTORY_SEPARATOR . $name;
        }

        return $this->options['path'] . $name . '.php';
    }

    /**
     * 读取底层原始数据。
     */
    protected function getRaw(string $name): ?array
    {
        $filename = $this->getCacheKey($name);

        if (!is_file($filename)) {
            return null;
        }

        $content = @file_get_contents($filename);

        if ($content === false || strlen($content) < 32) {
            return null;
        }

        // 文件头部为 PHP 退出保护结构，第 8~20 字节记录 12 位配置时长
        $duration = (int) substr($content, 8, 12);

        if ($duration !== 0 && time() - $duration > filemtime($filename)) {
            // 已过期，删除缓存文件
            $this->unlink($filename);

            return null;
        }

        $data = substr($content, 32);

        if ($this->options['data_compress'] && function_exists('gzcompress')) {
            $data = gzuncompress($data);
        }

        if (!is_string($data)) {
            return null;
        }

        // 剩余 TTL：永久为 0；否则按 mtime + 配置时长折算，至少保留 1 秒
        $ttl = $duration === 0
            ? 0
            : max(1, $duration + (int) filemtime($filename) - time());

        return ['content' => $data, 'ttl' => $ttl];
    }

    /**
     * 判断缓存是否存在。
     */
    public function has(string $key): bool
    {
        return $this->getRaw($key) !== null;
    }

    /**
     * 读取缓存，数据损坏时自愈删除。
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->getRaw($key);

        if ($raw === null) {
            return $this->resolveDefault($default);
        }

        try {
            return $this->unserialize($raw['content']);
        } catch (InvalidArgumentException) {
            $this->delete($key);

            return $this->resolveDefault($default);
        }
    }

    /**
     * 写入缓存。
     */
    public function set(string $key, mixed $value, int|DateInterval|DateTimeInterface|null $ttl = null): bool
    {
        if ($ttl === null) {
            $ttl = (int) $this->options['expire'];
        }

        $duration = $this->getExpireTime($ttl);
        $filename = $this->getCacheKey($key);

        $dir = dirname($filename);

        if (!is_dir($dir)) {
            try {
                mkdir($dir, 0755, true);
            } catch (Throwable) {
                // 目录创建失败时由 file_put_contents 返回错误
            }
        }

        $data = $this->serialize($value);

        if ($this->options['data_compress'] && function_exists('gzcompress')) {
            $data = gzcompress($data, 3);
        }

        $data = "<?php\n//" . sprintf('%012d', $duration) . "\n exit();?>\n" . $data;

        $result = file_put_contents($filename, $data, LOCK_EX);

        if ($result !== false) {
            clearstatcache();

            return true;
        }

        return false;
    }

    /**
     * 删除缓存。
     */
    public function delete(string $key): bool
    {
        return $this->unlink($this->getCacheKey($key));
    }

    /**
     * 清空全部缓存。
     */
    public function clear(): bool
    {
        $dirname = $this->options['path'] . $this->options['prefix'];

        $this->rmdir($dirname);

        return true;
    }

    /**
     * 判断文件存在后删除。
     */
    private function unlink(string $path): bool
    {
        try {
            return is_file($path) && unlink($path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 递归删除目录内容。
     */
    private function rmdir(string $dirname): bool
    {
        if (!is_dir($dirname)) {
            return false;
        }

        $items = new FilesystemIterator($dirname);

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->rmdir($item->getPathname());
            } else {
                $this->unlink($item->getPathname());
            }
        }

        @rmdir($dirname);

        return true;
    }
}
