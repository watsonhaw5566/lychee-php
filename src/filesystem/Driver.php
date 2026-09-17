<?php

declare(strict_types=1);

namespace Lychee\filesystem;

use League\Flysystem\DirectoryListing;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\ReadOnly\ReadOnlyFilesystemAdapter;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Lychee\http\UploadedFile;
use ReflectionObject;
use RuntimeException;
use Throwable;

/**
 * 文件系统驱动抽象基类。
 *
 * 封装 League\Flysystem，提供 put/get/delete/copy/move 等操作。
 */
abstract class Driver
{
    protected Filesystem $filesystem;

    protected FilesystemAdapter $adapter;

    protected PathPrefixer $prefixer;

    /** @var array<string, mixed> */
    protected array $config = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->config, $config);

        $separator = $this->config['directory_separator'] ?? DIRECTORY_SEPARATOR;
        $root      = $this->config['root']                ?? '';

        if (isset($this->config['prefix'])) {
            $root = rtrim($root, '\\/') . $separator . ltrim((string) $this->config['prefix'], '\\/');
        }

        $this->prefixer = new PathPrefixer($root, $separator);

        $this->config['root'] = $root;

        $this->adapter    = $this->wrapAdapter($this->createAdapter());
        $this->filesystem = new Filesystem($this->adapter, $this->extractFilesystemOptions($this->config));
    }

    abstract protected function createAdapter(): FilesystemAdapter;

    /**
     * 获取文件完整路径。
     */
    public function path(string $path): string
    {
        return $this->prefixer->prefixPath($path);
    }

    public function exists(string $path): bool
    {
        return $this->filesystem->has($path);
    }

    public function missing(string $path): bool
    {
        return !$this->exists($path);
    }

    public function fileExists(string $path): bool
    {
        return $this->filesystem->fileExists($path);
    }

    public function fileMissing(string $path): bool
    {
        return !$this->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->filesystem->directoryExists($path);
    }

    public function directoryMissing(string $path): bool
    {
        return !$this->directoryExists($path);
    }

    public function get(string $path): ?string
    {
        try {
            return $this->filesystem->read($path);
        } catch (UnableToReadFile $e) {
            return null;
        }
    }

    public function getVisibility(string $path): string
    {
        return $this->filesystem->visibility($path) === Visibility::PUBLIC ? 'public' : 'private';
    }

    public function setVisibility(string $path, string $visibility): bool
    {
        try {
            $this->filesystem->setVisibility($path, $visibility);
        } catch (UnableToSetVisibility) {
            return false;
        }

        return true;
    }

    public function prepend(string $path, string $data, string $separator = PHP_EOL): bool
    {
        if ($this->fileExists($path)) {
            return $this->put($path, $data . $separator . $this->get($path));
        }

        return $this->put($path, $data);
    }

    public function append(string $path, string $data, string $separator = PHP_EOL): bool
    {
        if ($this->fileExists($path)) {
            return $this->put($path, $this->get($path) . $separator . $data);
        }

        return $this->put($path, $data);
    }

    public function delete(string|array $paths): bool
    {
        $paths   = is_array($paths) ? $paths : func_get_args();
        $success = true;

        foreach ($paths as $path) {
            try {
                $this->filesystem->delete($path);
            } catch (UnableToDeleteFile | UnableToDeleteDirectory) {
                $success = false;
            }
        }

        return $success;
    }

    public function copy(string $from, string $to): bool
    {
        try {
            $this->filesystem->copy($from, $to);
        } catch (UnableToCopyFile) {
            return false;
        }

        return true;
    }

    public function move(string $from, string $to): bool
    {
        try {
            $this->filesystem->move($from, $to);
        } catch (UnableToMoveFile) {
            return false;
        }

        return true;
    }

    public function size(string $path): int
    {
        return $this->filesystem->fileSize($path);
    }

    public function mimeType(string $path): string|false
    {
        try {
            return $this->filesystem->mimeType($path);
        } catch (UnableToRetrieveMetadata) {
            return false;
        }
    }

    public function lastModified(string $path): int
    {
        return $this->filesystem->lastModified($path);
    }

    protected function readStream(string $path): mixed
    {
        try {
            return $this->filesystem->readStream($path);
        } catch (UnableToReadFile) {
            return null;
        }
    }

    protected function writeStream(string $path, mixed $resource, array $options = []): bool
    {
        try {
            $this->filesystem->writeStream($path, $resource, $options);
        } catch (UnableToWriteFile | UnableToSetVisibility) {
            return false;
        }

        return true;
    }

    public function put(string $path, mixed $contents, mixed $options = []): bool
    {
        $options = is_string($options) ? ['visibility' => $options] : (array) $options;

        try {
            if (is_resource($contents)) {
                if ($this->writeStream($path, $contents, $options) === false) {
                    return false;
                }
            } else {
                $this->filesystem->write($path, (string) $contents, $options);
            }
        } catch (FilesystemException) {
            return false;
        }

        return true;
    }

    /**
     * 存储上传文件。
     *
     * 自动生成唯一文件名（保留原始扩展名），返回存储后的相对路径。
     *
     * @param  string      $path    存储目录（相对于磁盘根目录）
     * @param  UploadedFile $file   上传文件实例
     * @param  mixed       $options 写入选项
     * @return string|false 存储后的相对路径，失败返回 false
     */
    public function putFile(string $path, UploadedFile $file, mixed $options = []): string|false
    {
        $name = $this->generateUploadedName($file);

        return $this->putFileAs($path, $file, $name, $options);
    }

    /**
     * 以指定文件名存储上传文件。
     *
     * @param  string      $path    存储目录（相对于磁盘根目录）
     * @param  UploadedFile $file   上传文件实例
     * @param  string      $name    保存的文件名
     * @param  mixed       $options 写入选项
     * @return string|false 存储后的相对路径，失败返回 false
     */
    public function putFileAs(string $path, UploadedFile $file, string $name, mixed $options = []): string|false
    {
        if (!$file->isValid()) {
            return false;
        }

        $stream = $file->getStream();
        if ($stream === null) {
            return false;
        }

        $target = trim($path, '/\\') . '/' . ltrim($name, '/\\');
        $result = $this->put($target, $stream, $options);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $result ? $target : false;
    }

    private function generateUploadedName(UploadedFile $file): string
    {
        $ext = $file->extension();

        return bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
    }

    /**
     * 列出目录内容。
     */
    public function fileList(?string $directory = null, bool $recursive = false): DirectoryListing
    {
        return $this->filesystem->listContents($directory ?? '', $recursive);
    }

    /**
     * @return string[]
     */
    public function files(?string $directory = null, bool $recursive = false): array
    {
        return $this->fileList($directory, $recursive)
            ->filter(fn (StorageAttributes $a) => $a->isFile())
            ->sortByPath()
            ->map(fn (StorageAttributes $a) => $a->path())
            ->toArray();
    }

    /**
     * @return string[]
     */
    public function allFiles(?string $directory = null): array
    {
        return $this->files($directory, true);
    }

    /**
     * @return string[]
     */
    public function directories(?string $directory = null, bool $recursive = false): array
    {
        return $this->fileList($directory, $recursive)
            ->filter(fn (StorageAttributes $a) => $a->isDir())
            ->map(fn (StorageAttributes $a) => $a->path())
            ->toArray();
    }

    /**
     * @return string[]
     */
    public function allDirectories(?string $directory = null): array
    {
        return $this->directories($directory, true);
    }

    public function makeDirectory(string $path): bool
    {
        try {
            $this->filesystem->createDirectory($path);
        } catch (UnableToCreateDirectory | UnableToSetVisibility) {
            return false;
        }

        return true;
    }

    public function deleteDirectory(string $directory): bool
    {
        try {
            if (!$this->filesystem->directoryExists($directory)) {
                return false;
            }
            $this->filesystem->deleteDirectory($directory);
        } catch (UnableToDeleteDirectory) {
            return false;
        }

        return true;
    }

    public function url(string $path): string
    {
        $adapter = $this->unwrapAdapter($this->adapter);

        if (method_exists($adapter, 'getUrl')) {
            return (string) $adapter->getUrl($path);
        }

        if ($adapter instanceof LocalFilesystemAdapter) {
            if (isset($this->config['url'])) {
                return rtrim((string) $this->config['url'], '/') . '/' . ltrim($path, '/');
            }

            return $path;
        }

        throw new RuntimeException('This driver does not support retrieving URLs.');
    }

    public function getDriver(): Filesystem
    {
        return $this->filesystem;
    }

    public function getAdapter(): FilesystemAdapter
    {
        return $this->adapter;
    }

    protected function wrapAdapter(FilesystemAdapter $adapter): FilesystemAdapter
    {
        if (($this->config['read-only'] ?? false) === true) {
            return new ReadOnlyFilesystemAdapter($adapter);
        }

        return $adapter;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function extractFilesystemOptions(array $config): array
    {
        $options = [];
        foreach (['directory_visibility', 'disable_asserts', 'visibility'] as $key) {
            if (isset($config[$key])) {
                $options[$key] = $config[$key];
            }
        }

        return $options;
    }

    protected function unwrapAdapter(FilesystemAdapter $adapter): FilesystemAdapter
    {
        $unwrapped = $adapter;

        while (true) {
            if ($unwrapped instanceof PathPrefixedAdapter) {
                $inner = $this->readAdapterInner($unwrapped, 'adapter');
                if ($inner instanceof FilesystemAdapter) {
                    $unwrapped = $inner;
                    continue;
                }
                break;
            }

            if ($unwrapped instanceof ReadOnlyFilesystemAdapter) {
                $inner = $this->readAdapterInner($unwrapped, 'adapter');
                if ($inner instanceof FilesystemAdapter) {
                    $unwrapped = $inner;
                    continue;
                }
                break;
            }

            break;
        }

        return $unwrapped;
    }

    private function readAdapterInner(object $object, string $property): mixed
    {
        try {
            $reflection = new ReflectionObject($object);
            if (!$reflection->hasProperty($property)) {
                return null;
            }
            $prop = $reflection->getProperty($property);
            $prop->setAccessible(true);

            return $prop->getValue($object);
        } catch (Throwable) {
            return null;
        }
    }

    public function __call(string $method, array $parameters): mixed
    {
        return $this->filesystem->{$method}(...$parameters);
    }
}
