<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\Application;
use Lychee\cors\CorsMiddleware;
use Lychee\http\Kernel;
use Lychee\http\Request;
use Lychee\http\Response;
use PHPUnit\Framework\TestCase;

class CorsMiddlewareTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );
    }

    private function middleware(array $config = []): CorsMiddleware
    {
        return new CorsMiddleware($config);
    }

    /** @param array<string, string> $headers */
    private function request(string $method, array $headers = [], string $path = '/'): Request
    {
        return new Request(
            method: $method,
            path: $path,
            query: [],
            body: [],
            headers: $headers,
        );
    }

    public function test_actual_request_allows_all_origins_by_default(): void
    {
        $response = $this->middleware()->handle(
            $this->request('GET', ['Origin' => 'https://example.com']),
            static fn (Request $_r): Response => new Response('ok'),
        );

        $this->assertSame('*', $response->headers['Access-Control-Allow-Origin']);
        $this->assertArrayNotHasKey('Vary', $response->headers);
    }

    public function test_preflight_short_circuits_with_cors_headers(): void
    {
        $reached = false;
        $next    = static function (Request $_r) use (&$reached): Response {
            $reached = true;

            return new Response('ok');
        };

        $response = $this->middleware()->handle(
            $this->request('OPTIONS', [
                'Origin'                        => 'https://example.com',
                'Access-Control-Request-Method' => 'POST',
            ]),
            $next,
        );

        $this->assertFalse($reached, '预检请求不应进入控制器');
        $this->assertSame(204, $response->status);
        $this->assertSame('*', $response->headers['Access-Control-Allow-Origin']);
        $this->assertSame('GET, POST, PUT, PATCH, DELETE, OPTIONS', $response->headers['Access-Control-Allow-Methods']);
        $this->assertSame('Content-Type, Authorization, X-Requested-With', $response->headers['Access-Control-Allow-Headers']);
        $this->assertSame('86400', $response->headers['Access-Control-Max-Age']);
    }

    public function test_request_without_origin_passes_through(): void
    {
        $response = $this->middleware()->handle(
            $this->request('GET'),
            static fn (Request $_r): Response => new Response('ok'),
        );

        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
    }

    public function test_disallowed_origin_gets_no_cors_headers(): void
    {
        $response = $this->middleware(['allowed_origins' => ['https://allowed.com']])->handle(
            $this->request('GET', ['Origin' => 'https://evil.com']),
            static fn (Request $_r): Response => new Response('ok'),
        );

        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
    }

    public function test_whitelisted_origin_is_reflected_with_vary(): void
    {
        $response = $this->middleware(['allowed_origins' => ['https://allowed.com']])->handle(
            $this->request('GET', ['Origin' => 'https://allowed.com']),
            static fn (Request $_r): Response => new Response('ok'),
        );

        $this->assertSame('https://allowed.com', $response->headers['Access-Control-Allow-Origin']);
        $this->assertSame('Origin', $response->headers['Vary']);
    }

    public function test_wildcard_origin_pattern_matches_subdomains(): void
    {
        $middleware = $this->middleware(['allowed_origins' => ['https://*.example.com']]);

        $allowed = $middleware->handle(
            $this->request('GET', ['Origin' => 'https://admin.example.com']),
            static fn (Request $_r): Response => new Response('ok'),
        );
        $this->assertSame('https://admin.example.com', $allowed->headers['Access-Control-Allow-Origin']);

        $denied = $middleware->handle(
            $this->request('GET', ['Origin' => 'https://evil.com']),
            static fn (Request $_r): Response => new Response('ok'),
        );
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $denied->headers);
    }

    public function test_credentials_mode_reflects_origin_instead_of_wildcard(): void
    {
        $response = $this->middleware([
            'allowed_origins'     => ['*'],
            'allowed_credentials' => true,
        ])->handle(
            $this->request('GET', ['Origin' => 'https://example.com']),
            static fn (Request $_r): Response => new Response('ok'),
        );

        $this->assertSame('https://example.com', $response->headers['Access-Control-Allow-Origin']);
        $this->assertSame('true', $response->headers['Access-Control-Allow-Credentials']);
        $this->assertSame('Origin', $response->headers['Vary']);
    }

    public function test_credentials_preflight_echoes_request_headers_with_wildcard(): void
    {
        $response = $this->middleware([
            'allowed_headers'     => ['*'],
            'allowed_credentials' => true,
        ])->handle(
            $this->request('OPTIONS', [
                'Origin'                         => 'https://example.com',
                'Access-Control-Request-Method'  => 'POST',
                'Access-Control-Request-Headers' => 'X-Custom, Content-Type',
            ]),
            static fn (Request $_r): Response => new Response('ok'),
        );

        $this->assertSame('X-Custom, Content-Type', $response->headers['Access-Control-Allow-Headers']);
        $this->assertSame('https://example.com', $response->headers['Access-Control-Allow-Origin']);
    }

    public function test_exposed_headers_added_to_actual_response(): void
    {
        $response = $this->middleware(['exposed_headers' => ['X-Total', 'X-Request-Id']])->handle(
            $this->request('GET', ['Origin' => 'https://example.com']),
            static fn (Request $_r): Response => new Response('ok'),
        );

        $this->assertSame('X-Total, X-Request-Id', $response->headers['Access-Control-Expose-Headers']);
    }

    public function test_kernel_handles_preflight_for_unregistered_options_route(): void
    {
        // /users 资源只注册了 GET/POST/PUT/PATCH/DELETE，未注册 OPTIONS
        $config = $this->app->container->get('config');
        $config->set([CorsMiddleware::class], 'middleware');

        $kernel = $this->app->container->get(Kernel::class);

        $response = $kernel->handle($this->request('OPTIONS', [
            'Origin'                        => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
        ], '/users'));

        $this->assertSame(204, $response->status);
        $this->assertSame('*', $response->headers['Access-Control-Allow-Origin']);
        $this->assertSame('GET, POST, PUT, PATCH, DELETE, OPTIONS', $response->headers['Access-Control-Allow-Methods']);
    }

    public function test_kernel_unregistered_options_without_cors_middleware_returns_204(): void
    {
        $kernel = $this->app->container->get(Kernel::class);

        $response = $kernel->handle($this->request('OPTIONS', [
            'Origin'                        => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
        ], '/users'));

        $this->assertSame(204, $response->status);
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
    }
}
