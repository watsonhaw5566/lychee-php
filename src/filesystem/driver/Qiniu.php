<?php

declare(strict_types=1);

namespace Lychee\filesystem\driver;

use InvalidArgumentException;
use Lychee\filesystem\Driver;
use Overtrue\Flysystem\Qiniu\QiniuAdapter;

/**
 * 七牛云 Kodo 文件系统驱动。
 */
class Qiniu extends Driver
{
    /** @var array<string, mixed> */
    protected array $config = [
        'access_key' => '',
        'secret_key' => '',
        'bucket'     => '',
        'domain'     => '',
    ];

    protected function createAdapter(): QiniuAdapter
    {
        $this->validateConfig();

        return new QiniuAdapter(
            (string) $this->config['access_key'],
            (string) $this->config['secret_key'],
            (string) $this->config['bucket'],
            (string) $this->config['domain'],
        );
    }

    private function validateConfig(): void
    {
        $required = ['access_key', 'secret_key', 'bucket', 'domain'];

        foreach ($required as $key) {
            if (empty($this->config[$key])) {
                throw new InvalidArgumentException(
                    "Qiniu Kodo driver requires '{$key}' in the config."
                );
            }
        }
    }
}
