<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\console\Application as ConsoleApplication;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\console\command\RouteCacheCommand;
use Lychee\routing\Router;
use PHPUnit\Framework\TestCase;

class RouteCacheCommandTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = RouteCacheCommand::cacheFilePath(STUB_DIR . '/runtime');

        // 避免缓存文件串扰其他测试
        @unlink($this->cacheFile);
        @unlink($this->cacheFile . '.tmp');
    }

    protected function tearDown(): void
    {
        @unlink($this->cacheFile);
        @unlink($this->cacheFile . '.tmp');
    }

    private function createApp(): Application
    {
        return new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );
    }

    public function test_command_is_registered(): void
    {
        $console = $this->createApp()->container->get(ConsoleApplication::class);

        $this->assertTrue($console->has('route:cache'));
    }

    public function test_command_generates_cache_file(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(ConsoleApplication::class);

        $code = $console->run(new Input(['route:cache']), new Output());

        $this->assertSame(0, $code);
        $this->assertFileExists($this->cacheFile);

        $content = file_get_contents($this->cacheFile);

        // 动态路由的编译结果（命名捕获组）应完整保留
        $this->assertStringContainsString("(?P<id>[^/]+)", $content);
        $this->assertStringContainsString('route:cache', $content);
    }

    public function test_cached_routes_are_identical_to_scanned_routes(): void
    {
        // 实时扫描的路由表
        $scannedRoutes = $this->createApp()->container->get(Router::class)->getRoutes();

        // 生成缓存
        $app     = $this->createApp();
        $console = $app->container->get(ConsoleApplication::class);
        $this->assertSame(0, $console->run(new Input(['route:cache']), new Output()));

        // 从缓存加载的路由表：顺序与内容必须完全一致
        $cachedRouter = $this->createApp()->container->get(Router::class);

        $this->assertTrue($cachedRouter->isLoadedFromCache());
        $this->assertSame($scannedRoutes, $cachedRouter->getRoutes());
    }

    public function test_dynamic_route_dispatches_and_extracts_params_from_cache(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(ConsoleApplication::class);
        $this->assertSame(0, $console->run(new Input(['route:cache']), new Output()));

        $router = $this->createApp()->container->get(Router::class);

        // {id} 动态路由：匹配成功且参数正确提取
        $match = $router->dispatch('GET', '/users/123');

        $this->assertSame('read', $match->action);
        $this->assertSame(['id' => '123'], $match->params);

        // 未注册的路径仍然正常 404
        $this->expectException(\Lychee\routing\RouteNotFoundException::class);
        $router->dispatch('DELETE', '/unknown/1');
    }

    public function test_command_overwrites_stale_cache(): void
    {
        // 先制造一份失效缓存
        file_put_contents($this->cacheFile, '<?php return ["routes" => []];');

        $app     = $this->createApp();
        $console = $app->container->get(ConsoleApplication::class);

        $this->assertSame(0, $console->run(new Input(['route:cache']), new Output()));

        $router = $this->createApp()->container->get(Router::class);

        $this->assertTrue($router->isLoadedFromCache());
        $this->assertNotEmpty($router->getRoutes());
    }
}
