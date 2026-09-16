<?php

declare(strict_types=1);

namespace Lychee\filesystem\driver;

use InvalidArgumentException;
use Lychee\filesystem\Driver;
use yzh52521\Flysystem\Oss\OssAdapter;

/**
 * 阿里云 OSS 文件系统驱动。
 */
class Aliyun extends Driver
{
    /** @var array<string, mixed> */
    protected array $config = [
        'access_id'     => '',
        'access_secret' => '',
        'bucket'        => '',
        'endpoint'      => '',
        'isCName'       => false,
        'cdn'           => '',
    ];

    protected function createAdapter(): OssAdapter
    {
        $this->validateConfig();

        $adapter = new OssAdapter($this->config);

        if (!empty($this->config['cdn']) && method_exists($adapter, 'setCdnUrl')) {
            $adapter->setCdnUrl((string) $this->config['cdn']);
        }

        return $adapter;
    }

    private function validateConfig(): void
    {
        $required = ['access_id', 'access_secret', 'bucket', 'endpoint'];

        foreach ($required as $key) {
            if (empty($this->config[$key])) {
                throw new InvalidArgumentException(
                    "Aliyun OSS driver requires '{$key}' in the config."
                );
            }
        }
    }
}
