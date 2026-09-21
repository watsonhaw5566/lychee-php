<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\routing\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    public function test_route_prefix_is_applied_to_all_routes(): void
    {
        $router = new Router('api');
        $router->registerDirectory(
            STUB_DIR . '/app/controller',
            'Tests\\stub\\app\\controller',
        );

        $routes = $router->getRoutes();
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            // 根路由为 /api，其余路由以 /api/ 开头
            $this->assertTrue(
                str_starts_with($route['path'], '/api/') || $route['path'] === '/api',
                "路径 {$route['path']} 未包含 /api 前缀",
            );
        }
    }

    public function test_empty_route_prefix_does_not_modify_paths(): void
    {
        $router = new Router('');
        $router->registerDirectory(
            STUB_DIR . '/app/controller',
            'Tests\\stub\\app\\controller',
        );

        $routes = $router->getRoutes();
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $this->assertStringStartsNotWith('/api/', $route['path']);
        }
    }

    public function test_dispatch_with_prefix_matches_prefixed_path(): void
    {
        $router = new Router('api');
        $router->registerDirectory(
            STUB_DIR . '/app/controller',
            'Tests\\stub\\app\\controller',
        );

        $match = $router->dispatch('GET', '/api/users');
        $this->assertSame('index', $match->action);
    }

    public function test_dispatch_without_prefix_returns_404_for_prefixed_path(): void
    {
        $router = new Router('');
        $router->registerDirectory(
            STUB_DIR . '/app/controller',
            'Tests\\stub\\app\\controller',
        );

        $this->expectException(\Lychee\routing\RouteNotFoundException::class);
        $router->dispatch('GET', '/api/users');
    }

    public function test_route_prefix_trims_slashes(): void
    {
        $router = new Router('/api/v1/');
        $router->registerDirectory(
            STUB_DIR . '/app/controller',
            'Tests\\stub\\app\\controller',
        );

        $routes = $router->getRoutes();
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            // 根路由为 /api/v1，其余路由以 /api/v1/ 开头
            $this->assertTrue(
                str_starts_with($route['path'], '/api/v1/') || $route['path'] === '/api/v1',
                "路径 {$route['path']} 未包含 /api/v1 前缀",
            );
        }

        $match = $router->dispatch('GET', '/api/v1/users');
        $this->assertSame('index', $match->action);
    }

    public function test_root_route_with_prefix_works(): void
    {
        $router = new Router('api');
        $router->registerDirectory(
            STUB_DIR . '/app/controller',
            'Tests\\stub\\app\\controller',
        );

        // #[Route('/')] 加前缀后应为 /api（无尾部斜杠），请求 /api 和 /api/ 都应匹配
        $match = $router->dispatch('GET', '/api');
        $this->assertSame('index', $match->action);

        $match = $router->dispatch('GET', '/api/');
        $this->assertSame('index', $match->action);
    }

    public function test_method_route_prefix_empty_skips_global_prefix(): void
    {
        $router = new Router('api');
        $router->registerController(\Tests\stub\app\prefix\HealthController::class);

        // prefix: '' 跳过全局前缀，路径为 /health
        $match = $router->dispatch('GET', '/health');
        $this->assertSame('health', $match->action);

        // 全局前缀路径不应匹配
        $this->expectException(\Lychee\routing\RouteNotFoundException::class);
        $router->dispatch('GET', '/api/health');
    }

    public function test_method_route_prefix_custom_replaces_global_prefix(): void
    {
        $router = new Router('api');
        $router->registerController(\Tests\stub\app\prefix\HealthController::class);

        // prefix: 'admin' 替代全局前缀，路径为 /admin/dashboard
        $match = $router->dispatch('GET', '/admin/dashboard');
        $this->assertSame('dashboard', $match->action);

        // 全局前缀路径不应匹配
        $this->expectException(\Lychee\routing\RouteNotFoundException::class);
        $router->dispatch('GET', '/api/dashboard');
    }

    public function test_method_route_without_prefix_uses_global_prefix(): void
    {
        $router = new Router('api');
        $router->registerController(\Tests\stub\app\prefix\HealthController::class);

        // 未设置 prefix，使用全局前缀 /api/ping
        $match = $router->dispatch('GET', '/api/ping');
        $this->assertSame('ping', $match->action);
    }

    public function test_resource_prefix_empty_skips_global_prefix_for_all_actions(): void
    {
        $router = new Router('api');
        $router->registerController(\Tests\stub\app\prefix\OrderController::class);

        // #[Resource('orders', prefix: '')] 所有资源路由跳过全局前缀
        $match = $router->dispatch('GET', '/orders');
        $this->assertSame('index', $match->action);

        $match = $router->dispatch('GET', '/orders/1');
        $this->assertSame('read', $match->action);

        // 全局前缀路径不应匹配
        $this->expectException(\Lychee\routing\RouteNotFoundException::class);
        $router->dispatch('GET', '/api/orders');
    }

    public function test_method_prefix_overrides_class_prefix(): void
    {
        $router = new Router('api');
        $router->registerController(\Tests\stub\app\prefix\OrderController::class);

        // OrderController 是 #[Resource('orders', prefix: '')]，类级 prefix 为空
        // 若方法显式设置 prefix，则以方法级为准
        // 这里 OrderController 的方法没有显式 #[Route]，所以都使用类级 prefix: ''
        // 验证类级 prefix: '' 生效
        $routes = $router->getRoutes();
        $orderRoutes = array_filter($routes, fn($r) => $r['controller'] === \Tests\stub\app\prefix\OrderController::class);
        foreach ($orderRoutes as $route) {
            $this->assertStringStartsNotWith('/api', $route['path']);
        }
    }
}
