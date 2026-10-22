<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\http\Kernel;
use Lychee\http\Request;
use PHPUnit\Framework\TestCase;

/**
 * 验证 request() 助手函数与中间件管道、控制器拿到同一个请求实例。
 *
 * 背景：Kernel::handle() 中 withRouteParams() 会克隆请求，若克隆体
 * 未注册到容器，中间件在克隆体上 setLoginId() 后，控制器通过
 * request()->loginId() 读到的是懒加载单例创建的全新实例，loginId 为 null。
 */
class RequestInstanceTest extends TestCase
{
    private Application $app;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $this->kernel = $this->app->container->get(Kernel::class);
    }

    public function test_request_helper_reads_login_id_set_by_middleware(): void
    {
        $response = $this->kernel->handle(new Request('GET', '/request-context', [], [], []));

        $this->assertSame(200, $response->status);
        $data = json_decode($response->content, true);
        $this->assertSame(42, $data['loginId']);
    }

    public function test_request_helper_returns_the_handled_request_instance(): void
    {
        $this->kernel->handle(new Request('GET', '/request-context', [], [], []));

        // request() 返回的应是 Kernel 实际处理的实例，而非容器懒加载
        // singleton 创建的另一个 capture() 实例
        $this->assertSame(42, request()->loginId());
    }
}
