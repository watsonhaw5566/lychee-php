<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\Application;
use Lychee\auth\exception\NoPermissionException;
use Lychee\auth\exception\NotLoginException;
use Lychee\http\ExceptionHandler;
use Lychee\http\HttpException;
use Lychee\http\Request;
use Lychee\orm\exception\ModelNotFoundException;
use Lychee\routing\RouteNotFoundException;
use Lychee\validate\ValidateException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use ReflectionClass;

class ExceptionHandlerTest extends TestCase
{
    private Application $app;
    private ExceptionHandler $handler;

    protected function setUp(): void
    {
        $this->app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $this->handler = $this->app->container->get(ExceptionHandler::class);

        putenv('APP_DEBUG=false');
    }

    protected function tearDown(): void
    {
        putenv('APP_DEBUG');
    }

    private function jsonRequest(): Request
    {
        return new Request(
            method: 'GET',
            path: '/test',
            query: [],
            body: [],
            headers: ['Accept' => 'application/json'],
        );
    }

    // ── shouldReport ──────────────────────────────────────────────

    public function test_http_exception_not_reported(): void
    {
        $this->assertFalse($this->invokeShouldReport(new HttpException(404, 'not found')));
    }

    public function test_validate_exception_not_reported(): void
    {
        $this->assertFalse($this->invokeShouldReport(new ValidateException('bad')));
    }

    public function test_route_not_found_not_reported(): void
    {
        $this->assertFalse($this->invokeShouldReport(new RouteNotFoundException()));
    }

    public function test_runtime_exception_is_reported(): void
    {
        $this->assertTrue($this->invokeShouldReport(new RuntimeException('boom')));
    }

    // ── render: status code ──────────────────────────────────────

    public function test_http_exception_returns_its_status_code(): void
    {
        $response = $this->handler->render($this->jsonRequest(), new HttpException(418, 'teapot'));

        $this->assertSame(418, $response->status);
    }

    public function test_not_login_exception_returns_401(): void
    {
        $response = $this->handler->render($this->jsonRequest(), new NotLoginException('未登录'));

        $this->assertSame(401, $response->status);
    }

    public function test_no_permission_exception_returns_403(): void
    {
        $response = $this->handler->render($this->jsonRequest(), new NoPermissionException('无权限'));

        $this->assertSame(403, $response->status);
    }

    public function test_model_not_found_exception_returns_404(): void
    {
        $response = $this->handler->render($this->jsonRequest(), new ModelNotFoundException('User'));

        $this->assertSame(404, $response->status);
    }

    public function test_runtime_exception_returns_500(): void
    {
        $response = $this->handler->render($this->jsonRequest(), new RuntimeException('boom'));

        $this->assertSame(500, $response->status);
    }

    // ── render: JSON structure ───────────────────────────────────

    public function test_json_response_has_unified_structure(): void
    {
        $response = $this->handler->render($this->jsonRequest(), new HttpException(404, 'not found'));
        $data     = json_decode($response->content, true);

        $this->assertSame(404, $data['code']);
        $this->assertIsString($data['msg']);
        $this->assertArrayHasKey('data', $data);
        $this->assertNull($data['data']);
    }

    public function test_validate_exception_preserves_field_errors(): void
    {
        $errors   = ['name' => '名称必填', 'age' => '年龄必须大于0'];
        $response = $this->handler->render($this->jsonRequest(), new ValidateException($errors));
        $data     = json_decode($response->content, true);

        $this->assertSame(400, $data['code']);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame($errors, $data['errors']);
    }

    public function test_validate_exception_single_error_with_key(): void
    {
        $response = $this->handler->render($this->jsonRequest(), new ValidateException('名称必填', 'name'));
        $data     = json_decode($response->content, true);

        $this->assertSame(400, $data['code']);
        $this->assertSame(['name' => '名称必填'], $data['errors']);
    }

    // ── render: headers passthrough ──────────────────────────────

    public function test_http_exception_headers_are_applied_to_response(): void
    {
        $e = new HttpException(429, 'too many requests', 0, null, [
            'Retry-After' => '60',
        ]);

        $response = $this->handler->render($this->jsonRequest(), $e);

        $this->assertSame('60', $response->headers['Retry-After'] ?? null);
    }

    // ── wantsHtml / acceptQuality ────────────────────────────────

    public function test_wants_html_for_empty_accept(): void
    {
        $request = new Request('GET', '/', [], [], []);

        $this->assertTrue($this->invokeWantsHtml($request));
    }

    public function test_wants_html_for_text_html(): void
    {
        $request = new Request('GET', '/', [], [], ['Accept' => 'text/html']);

        $this->assertTrue($this->invokeWantsHtml($request));
    }

    public function test_does_not_want_html_for_application_json(): void
    {
        $request = new Request('GET', '/', [], [], ['Accept' => 'application/json']);

        $this->assertFalse($this->invokeWantsHtml($request));
    }

    public function test_wants_html_when_html_q_higher_than_json(): void
    {
        $request = new Request('GET', '/', [], [], [
            'Accept' => 'text/html;q=1.0,application/json;q=0.9',
        ]);

        $this->assertTrue($this->invokeWantsHtml($request));
    }

    public function test_does_not_want_html_when_json_q_higher_than_html(): void
    {
        $request = new Request('GET', '/', [], [], [
            'Accept' => 'text/html;q=0.5,application/json;q=1.0',
        ]);

        $this->assertFalse($this->invokeWantsHtml($request));
    }

    public function test_accept_quality_defaults_to_one(): void
    {
        $this->assertSame(1.0, $this->invokeAcceptQuality('text/html', 'text/html'));
    }

    public function test_accept_quality_parses_q_value(): void
    {
        $this->assertSame(0.9, $this->invokeAcceptQuality('text/html;q=0.9', 'text/html'));
    }

    public function test_accept_quality_returns_zero_when_not_present(): void
    {
        $this->assertSame(0.0, $this->invokeAcceptQuality('application/json', 'text/html'));
    }

    // ── helpers ──────────────────────────────────────────────────

    private function invokeShouldReport(Throwable $e): bool
    {
        $ref    = new ReflectionClass($this->handler);
        $method = $ref->getMethod('shouldReport');
        $method->setAccessible(true);

        return (bool) $method->invoke($this->handler, $e);
    }

    private function invokeWantsHtml(Request $request): bool
    {
        $ref    = new ReflectionClass($this->handler);
        $method = $ref->getMethod('wantsHtml');
        $method->setAccessible(true);

        return (bool) $method->invoke($this->handler, $request);
    }

    private function invokeAcceptQuality(string $accept, string $mediaType): float
    {
        $ref    = new ReflectionClass($this->handler);
        $method = $ref->getMethod('acceptQuality');
        $method->setAccessible(true);

        return (float) $method->invoke($this->handler, $accept, $mediaType);
    }
}
