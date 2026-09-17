<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\console\Input;
use Lychee\console\Output;
use PHPUnit\Framework\TestCase;

class MakeBaseCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lychee_make_base_' . uniqid();
        mkdir($this->tempDir . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_command_is_registered(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        $this->assertTrue($console->has('make:base'));
    }

    public function test_make_base_creates_basic_controller(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        $code = $console->run(new Input(['make:base', 'Index']), new Output());

        $this->assertSame(0, $code);

        $appNamespace   = $app->container->getNamespace();
        $controllerPath = $this->tempDir . '/app/controller/IndexController.php';

        $this->assertFileExists($controllerPath);

        $controller = file_get_contents($controllerPath);
        $this->assertStringContainsString("namespace {$appNamespace}\\controller;", $controller);
        $this->assertStringContainsString('use Lychee\\http\\JsonResponse;', $controller);
        $this->assertStringContainsString('use Lychee\\routing\\Route;', $controller);
        $this->assertStringContainsString("#[Route('/index')]\nclass IndexController", $controller);
        $this->assertStringContainsString("#[Route('/')]\n    public function index(): JsonResponse", $controller);
        $this->assertStringContainsString('return new JsonResponse(null);', $controller);
        // 不继承任何基类，不包含资源路由相关内容
        $this->assertStringNotContainsString('extends', $controller);
        $this->assertStringNotContainsString('ResourceController', $controller);
        $this->assertStringNotContainsString('#[Resource', $controller);
    }

    public function test_make_base_accepts_controller_suffix(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        $code = $console->run(new Input(['make:base', 'ApiController']), new Output());

        $this->assertSame(0, $code);
        $this->assertFileExists($this->tempDir . '/app/controller/ApiController.php');
    }

    public function test_make_base_capitalizes_first_letter(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        $code = $console->run(new Input(['make:base', 'admin']), new Output());

        $this->assertSame(0, $code);
        $this->assertFileExists($this->tempDir . '/app/controller/AdminController.php');

        $controller = file_get_contents($this->tempDir . '/app/controller/AdminController.php');
        $this->assertStringContainsString("#[Route('/admin')]\nclass AdminController", $controller);
    }

    public function test_make_base_fails_when_controller_exists(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        mkdir($this->tempDir . '/app/controller', 0777, true);
        file_put_contents($this->tempDir . '/app/controller/IndexController.php', '<?php');

        $code = $console->run(new Input(['make:base', 'Index']), new Output());

        $this->assertSame(1, $code);
    }

    public function test_make_base_rejects_invalid_name(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        $code = $console->run(new Input(['make:base', '123Invalid']), new Output());

        $this->assertSame(1, $code);
    }

    private function createApp(): Application
    {
        return new Application(
            basePath: $this->tempDir,
            controllerNamespace: 'App\\controller',
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
