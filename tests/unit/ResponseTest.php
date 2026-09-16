<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\Application;
use Lychee\http\HttpException;
use Lychee\http\Response;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    protected function setUp(): void
    {
        // 初始化应用容器，使 public_path() 辅助函数可用
        new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );
    }

    public function test_redirect_sets_location_and_default_status(): void
    {
        $response = Response::redirect('/login');

        $this->assertSame(302, $response->status);
        $this->assertSame('/login', $response->headers['Location']);
        $this->assertSame('', $response->content);
    }

    public function test_redirect_with_custom_status(): void
    {
        $response = Response::redirect('/new-url', 301);

        $this->assertSame(301, $response->status);
        $this->assertSame('/new-url', $response->headers['Location']);
    }

    public function test_redirect_preserves_extra_headers(): void
    {
        $response = Response::redirect('/login', 302, ['X-Custom' => 'value']);

        $this->assertSame('value', $response->headers['X-Custom']);
        $this->assertSame('/login', $response->headers['Location']);
    }

    public function test_download_from_public_directory(): void
    {
        $response = Response::download('index.php');

        $realPath = app('path.public') . 'index.php';

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('attachment', $response->headers['Content-Disposition']);
        $this->assertStringContainsString('index.php', $response->headers['Content-Disposition']);
        $this->assertSame((string) filesize($realPath), $response->headers['Content-Length']);
        $this->assertNotEmpty($response->content);
    }

    public function test_download_with_custom_filename(): void
    {
        $response = Response::download('index.php', 'custom-name.php');

        $this->assertStringContainsString('custom-name.php', $response->headers['Content-Disposition']);
    }

    public function test_download_with_absolute_path(): void
    {
        $absolute = app('path.public') . 'index.php';

        $response = Response::download($absolute);

        $this->assertSame(200, $response->status);
        $this->assertNotEmpty($response->content);
    }

    public function test_download_nonexistent_file_throws_404(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);

        try {
            Response::download('does-not-exist.txt');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());

            throw $e;
        }
    }
}
