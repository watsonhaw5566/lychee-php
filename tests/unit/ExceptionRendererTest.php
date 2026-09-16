<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\Application;
use Lychee\http\Kernel;
use Lychee\http\Request;
use Lychee\view\ExceptionRenderer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ExceptionRendererTest extends TestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        $app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $this->kernel = $app->container->get(Kernel::class);
    }

    protected function tearDown(): void
    {
        putenv('APP_DEBUG');
    }

    public function test_renderer_produces_html_with_exception_details(): void
    {
        $renderer = new ExceptionRenderer(cachePath: STUB_DIR . '/runtime/twig');
        $e        = new RuntimeException('Something went wrong');

        $html = $renderer->render(status: 500, e: $e, method: 'GET', url: '/test');

        $this->assertStringContainsString('500', $html);
        $this->assertStringContainsString('Something went wrong', $html);
        $this->assertStringContainsString('RuntimeException', $html);
        $this->assertStringContainsString('/test', $html);
        $this->assertStringContainsString('GET', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
    }

    public function test_debug_mode_returns_html_exception_page(): void
    {
        putenv('APP_DEBUG=true');

        $request = new Request(
            method: 'GET',
            path: '/non-existent-route',
            query: [],
            body: [],
            headers: ['Accept' => 'text/html'],
        );

        $response = $this->kernel->handle($request);

        $this->assertSame(404, $response->status);
        $this->assertSame('text/html; charset=utf-8', $response->headers['Content-Type']);
        $this->assertNotEmpty($response->content);
        $this->assertStringContainsString('404', $response->content);
        $this->assertStringContainsString('RouteNotFoundException', $response->content);
    }

    public function test_non_debug_mode_returns_blank_page(): void
    {
        putenv('APP_DEBUG=false');

        $request = new Request(
            method: 'GET',
            path: '/non-existent-route',
            query: [],
            body: [],
            headers: ['Accept' => 'text/html'],
        );

        $response = $this->kernel->handle($request);

        $this->assertSame(404, $response->status);
        $this->assertSame('text/html; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame('', $response->content);
    }

    public function test_json_request_returns_json_response(): void
    {
        putenv('APP_DEBUG=true');

        $request = new Request(
            method: 'GET',
            path: '/non-existent-route',
            query: [],
            body: [],
            headers: ['Accept' => 'application/json'],
        );

        $response = $this->kernel->handle($request);

        $this->assertSame(404, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $data = json_decode($response->content, true);
        $this->assertSame(404, $data['code']);
        $this->assertArrayHasKey('msg', $data);
        $this->assertArrayHasKey('type', $data);
    }

    public function test_json_request_non_debug_returns_generic_message(): void
    {
        putenv('APP_DEBUG=false');

        $request = new Request(
            method: 'GET',
            path: '/non-existent-route',
            query: [],
            body: [],
            headers: ['Accept' => 'application/json'],
        );

        $response = $this->kernel->handle($request);

        $this->assertSame(404, $response->status);

        $data = json_decode($response->content, true);
        $this->assertSame(404, $data['code']);
        $this->assertSame('Not Found', $data['msg']);
        $this->assertArrayNotHasKey('trace', $data);
    }
}
