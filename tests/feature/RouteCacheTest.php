<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\http\Kernel;
use Lychee\http\Request;
use PHPUnit\Framework\TestCase;
use Tests\stub\app\controller\CacheController;
use Tests\stub\app\controller\CachedPostController;

class RouteCacheTest extends TestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        $app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $this->kernel = $app->container->get(Kernel::class);

        // 清理缓存，避免测试间串扰
        $app->container->get('cache')->clear();

        CacheController::resetCount();
        CachedPostController::resetCount();
    }

    /** @param array<string, string> $query */
    private function request(string $method, string $path, array $query = []): Request
    {
        return new Request(
            method: $method,
            path: $path,
            query: $query,
            body: [],
            headers: [],
        );
    }

    public function test_cached_route_returns_same_response_on_second_request(): void
    {
        // 第一次请求：控制器被调用，响应被缓存
        $response1 = $this->kernel->handle($this->request('GET', '/cached'));
        $this->assertSame(200, $response1->status);

        $data1 = json_decode($response1->content, true);
        $this->assertSame(1, $data1['count']);

        // 第二次请求：应命中缓存，控制器不再被调用
        $response2 = $this->kernel->handle($this->request('GET', '/cached'));
        $this->assertSame(200, $response2->status);

        $data2 = json_decode($response2->content, true);
        $this->assertSame(1, $data2['count'], '缓存命中时 count 应保持为 1');
        $this->assertSame($response1->content, $response2->content);
    }

    public function test_different_query_params_get_different_cache(): void
    {
        // page=1
        $r1 = $this->kernel->handle($this->request('GET', '/cached', ['page' => '1']));
        $this->assertSame(1, json_decode($r1->content, true)['count']);

        // page=2：不同查询参数，应产生新缓存条目
        $r2 = $this->kernel->handle($this->request('GET', '/cached', ['page' => '2']));
        $this->assertSame(2, json_decode($r2->content, true)['count'], '不同查询参数应产生新缓存条目');

        // page=1 再次请求：应命中缓存
        $r3 = $this->kernel->handle($this->request('GET', '/cached', ['page' => '1']));
        $this->assertSame(1, json_decode($r3->content, true)['count'], '相同查询参数应命中缓存');
    }

    public function test_post_request_is_not_cached(): void
    {
        // 先 GET 一次建立缓存
        $this->kernel->handle($this->request('GET', '/cached'));

        // POST 请求不应命中缓存，控制器应被调用
        $response = $this->kernel->handle($this->request('POST', '/cached'));
        // POST /cached 没有路由，会返回 404，但重要的是不命中 GET 的缓存
        $this->assertSame(404, $response->status);
    }

    public function test_uncached_route_always_calls_controller(): void
    {
        $r1 = $this->kernel->handle($this->request('GET', '/no-cache'));
        $this->assertSame(1, json_decode($r1->content, true)['count']);

        $r2 = $this->kernel->handle($this->request('GET', '/no-cache'));
        $this->assertSame(2, json_decode($r2->content, true)['count'], '未缓存路由每次都应调用控制器');
    }

    public function test_resource_cache_applies_to_index(): void
    {
        $r1 = $this->kernel->handle($this->request('GET', '/cached-posts'));
        $this->assertSame(1, json_decode($r1->content, true)['count']);

        $r2 = $this->kernel->handle($this->request('GET', '/cached-posts'));
        $this->assertSame(1, json_decode($r2->content, true)['count'], '资源路由 index 应被缓存');
    }

    public function test_resource_cache_applies_to_read(): void
    {
        $r1 = $this->kernel->handle($this->request('GET', '/cached-posts/1'));
        $this->assertSame(1, json_decode($r1->content, true)['count']);

        $r2 = $this->kernel->handle($this->request('GET', '/cached-posts/1'));
        $this->assertSame(1, json_decode($r2->content, true)['count'], '资源路由 read 应被缓存');
    }

    public function test_resource_post_action_is_not_cached(): void
    {
        $r1 = $this->kernel->handle($this->request('POST', '/cached-posts'));
        $this->assertSame(1, json_decode($r1->content, true)['count']);

        $r2 = $this->kernel->handle($this->request('POST', '/cached-posts'));
        $this->assertSame(2, json_decode($r2->content, true)['count'], 'POST 动作不应被缓存');
    }

    public function test_method_level_cache_overrides_resource_level(): void
    {
        $r1 = $this->kernel->handle($this->request('GET', '/cached-posts/custom'));
        $this->assertSame(1, json_decode($r1->content, true)['count']);

        $r2 = $this->kernel->handle($this->request('GET', '/cached-posts/custom'));
        $this->assertSame(1, json_decode($r2->content, true)['count'], '方法级 cache 应覆盖资源级 cache');
    }
}
