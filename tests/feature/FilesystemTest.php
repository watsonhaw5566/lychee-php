<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\filesystem\Driver;
use Lychee\filesystem\driver\Local;
use Lychee\filesystem\FilesystemManager;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

class FilesystemTest extends TestCase
{
    private Application $app;
    private FilesystemManager $manager;

    protected function setUp(): void
    {
        $this->app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $this->manager = $this->app->container->get(FilesystemManager::class);
    }

    public function test_filesystem_bound_to_container(): void
    {
        $this->assertTrue($this->app->container->has('filesystem'));
    }

    public function test_disk_returns_local_driver(): void
    {
        $disk = $this->manager->disk('local');

        $this->assertInstanceOf(Driver::class, $disk);
        $this->assertInstanceOf(Local::class, $disk);
    }

    public function test_can_write_and_read_file(): void
    {
        $disk    = $this->manager->disk('local');
        $path    = 'test-' . uniqid() . '.txt';
        $content = 'hello lychee filesystem';

        $this->assertTrue($disk->put($path, $content));
        $this->assertSame($content, $disk->get($path));
        $this->assertTrue($disk->exists($path));

        $disk->delete($path);
        $this->assertFalse($disk->exists($path));
    }

    public function test_default_disk_resolves(): void
    {
        $disk = $this->manager->disk();

        $this->assertInstanceOf(Driver::class, $disk);
    }

    public function test_aliyun_driver_validates_required_config(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Aliyun OSS driver requires 'access_id'");

        new \Lychee\filesystem\driver\Aliyun([
            'access_secret' => 'secret',
            'bucket'        => 'bucket',
            'endpoint'      => 'oss-cn-hangzhou.aliyuncs.com',
        ]);
    }

    public function test_qcloud_driver_validates_required_config(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Tencent COS driver requires 'app_id'");

        new \Lychee\filesystem\driver\Qcloud([
            'secret_id'  => 'id',
            'secret_key' => 'key',
            'region'     => 'ap-guangzhou',
            'bucket'     => 'bucket',
        ]);
    }
}
