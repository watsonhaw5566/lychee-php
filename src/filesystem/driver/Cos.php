<?php

declare(strict_types=1);

namespace Lychee\filesystem\driver;

use InvalidArgumentException;
use Lychee\filesystem\Driver;
use Overtrue\Flysystem\Cos\CosAdapter;

/**
 * 腾讯云 COS 文件系统驱动。
 */
class Cos extends Driver
{
    /** @var array<string, mixed> */
    protected array $config = [
        'app_id'     => '',
        'secret_id'  => '',
        'secret_key' => '',
        'region'     => 'ap-guangzhou',
        'bucket'     => '',
        'signed_url' => false,
        'use_https'  => true,
        'domain'     => '',
        'cdn'        => '',
    ];

    protected function createAdapter(): CosAdapter
    {
        $this->validateConfig();

        return new CosAdapter($this->config);
    }

    private function validateConfig(): void
    {
        $required = ['app_id', 'secret_id', 'secret_key', 'region', 'bucket'];

        foreach ($required as $key) {
            if (empty($this->config[$key])) {
                throw new InvalidArgumentException(
                    "Tencent COS driver requires '{$key}' in the config."
                );
            }
        }
    }
}
