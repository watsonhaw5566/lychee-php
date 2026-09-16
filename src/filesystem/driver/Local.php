<?php

declare(strict_types=1);

namespace Lychee\filesystem\driver;

use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;
use Lychee\filesystem\Driver;

/**
 * 本地文件系统驱动。
 */
class Local extends Driver
{
    /** @var array<string, mixed> */
    protected array $config = [
        'root' => '',
    ];

    protected function createAdapter(): LocalFilesystemAdapter
    {
        $visibility = PortableVisibilityConverter::fromArray(
            $this->config['permissions'] ?? [],
            $this->config['visibility']  ?? Visibility::PRIVATE
        );

        $links = ($this->config['links'] ?? null) === 'skip'
            ? LocalFilesystemAdapter::SKIP_LINKS
            : LocalFilesystemAdapter::DISALLOW_LINKS;

        return new LocalFilesystemAdapter(
            (string) $this->config['root'],
            $visibility,
            $this->config['lock'] ?? LOCK_EX,
            $links
        );
    }
}
