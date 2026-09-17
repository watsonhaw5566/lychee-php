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
            $this->assertStringStartsWith('/api/', $route['path']);
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
            $this->assertStringStartsWith('/api/v1/', $route['path']);
        }

        $match = $router->dispatch('GET', '/api/v1/users');
        $this->assertSame('index', $match->action);
    }
}
