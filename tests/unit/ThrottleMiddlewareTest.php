<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\Application;
use Lychee\http\Request;
use Lychee\http\Response;
use Lychee\throttle\ThrottleMiddleware;
use PHPUnit\Framework\TestCase;
use think\CacheManager;

class ThrottleMiddlewareTest extends TestCase
{
    private CacheManager $cache;

    protected function setUp(): void
    {
        $app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $this->cache = $app->container->get(CacheManager::class);

        // 清理可能残留的限流计数，避免测试间串扰
        $this->cache->clear();
    }

    public function test_allows_requests_under_limit(): void
    {
        $middleware = new ThrottleMiddleware($this->cache, [
            'max_attempts'  => 3,
            'decay_seconds' => 60,
            'key_type'      => 'all',
        ]);

        $request = new Request(
            method: 'GET',
            path: '/test',
            query: [],
            body: [],
            headers: [],
            ip: '127.0.0.1',
        );

        $next = fn (Request $_req): Response => new Response('ok', 200);

        $response = $middleware->handle($request, $next);

        $this->assertSame(200, $response->status);
        $this->assertSame('3', $response->headers['X-RateLimit-Limit']);
        $this->assertSame('2', $response->headers['X-RateLimit-Remaining']);
    }

    public function test_blocks_requests_over_limit(): void
    {
        $middleware = new ThrottleMiddleware($this->cache, [
            'max_attempts'  => 2,
            'decay_seconds' => 60,
            'key_type'      => 'all',
            'prefix'        => 'throttle:test:over:',
        ]);

        $request = new Request(
            method: 'GET',
            path: '/test',
            query: [],
            body: [],
            headers: [],
            ip: '127.0.0.1',
        );

        $next = fn (Request $_req): Response => new Response('ok', 200);

        // 前两次放行
        $this->assertSame(200, $middleware->handle($request, $next)->status);
        $this->assertSame(200, $middleware->handle($request, $next)->status);

        // 第三次被限流
        $response = $middleware->handle($request, $next);
        $this->assertSame(429, $response->status);
        $this->assertSame('0', $response->headers['X-RateLimit-Remaining']);
        $this->assertArrayHasKey('Retry-After', $response->headers);
    }

    public function test_ip_based_key_isolates_clients(): void
    {
        $middleware = new ThrottleMiddleware($this->cache, [
            'max_attempts'  => 1,
            'decay_seconds' => 60,
            'key_type'      => 'ip',
            'prefix'        => 'throttle:test:ip:',
        ]);

        $next = fn (Request $_req): Response => new Response('ok', 200);

        $reqA = new Request(method: 'GET', path: '/', query: [], body: [], headers: [], ip: '1.1.1.1');
        $reqB = new Request(method: 'GET', path: '/', query: [], body: [], headers: [], ip: '2.2.2.2');

        // A 用完额度
        $this->assertSame(200, $middleware->handle($reqA, $next)->status);
        $this->assertSame(429, $middleware->handle($reqA, $next)->status);

        // B 不受影响
        $this->assertSame(200, $middleware->handle($reqB, $next)->status);
    }

    public function test_disabled_headers(): void
    {
        $middleware = new ThrottleMiddleware($this->cache, [
            'max_attempts'  => 10,
            'decay_seconds' => 60,
            'key_type'      => 'all',
            'prefix'        => 'throttle:test:noheader:',
            'with_headers'  => false,
        ]);

        $request = new Request(method: 'GET', path: '/', query: [], body: [], headers: [], ip: '127.0.0.1');
        $next    = fn (Request $_req): Response => new Response('ok', 200);

        $response = $middleware->handle($request, $next);

        $this->assertSame(200, $response->status);
        $this->assertArrayNotHasKey('X-RateLimit-Limit', $response->headers);
    }
}
