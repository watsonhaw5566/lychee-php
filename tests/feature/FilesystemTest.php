<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\filesystem\Driver;
use Lychee\filesystem\driver\Local;
use Lychee\filesystem\FilesystemManager;
use Lychee\http\UploadedFile;
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

    public function test_put_file_stores_uploaded_file(): void
    {
        $disk = $this->manager->disk('local');

        // 创建一个临时文件作为上传源
        $tmpFile = tempnam(sys_get_temp_dir(), 'upl_');
        file_put_contents($tmpFile, 'uploaded content');

        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getStream')->willReturn(fopen($tmpFile, 'r'));
        $file->method('extension')->willReturn('txt');

        $stored = $disk->putFile('uploads', $file);

        $this->assertNotFalse($stored);
        $this->assertStringStartsWith('uploads/', $stored);
        $this->assertStringEndsWith('.txt', $stored);
        $this->assertTrue($disk->exists($stored));
        $this->assertSame('uploaded content', $disk->get($stored));

        $disk->delete($stored);
        unlink($tmpFile);
    }

    public function test_put_file_as_stores_with_custom_name(): void
    {
        $disk = $this->manager->disk('local');

        $tmpFile = tempnam(sys_get_temp_dir(), 'upl_');
        file_put_contents($tmpFile, 'custom named file');

        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getStream')->willReturn(fopen($tmpFile, 'r'));

        $stored = $disk->putFileAs('uploads', $file, 'avatar.png');

        $this->assertSame('uploads/avatar.png', $stored);
        $this->assertTrue($disk->exists($stored));
        $this->assertSame('custom named file', $disk->get($stored));

        $disk->delete($stored);
        unlink($tmpFile);
    }

    public function test_put_file_returns_false_for_invalid_file(): void
    {
        $disk = $this->manager->disk('local');

        $file = $this->createMock(UploadedFile::class);
        $file->method('isValid')->willReturn(false);

        $this->assertFalse($disk->putFile('uploads', $file));
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
