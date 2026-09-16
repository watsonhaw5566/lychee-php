<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\http\HttpException;
use Lychee\http\Request;
use Lychee\http\Response;
use PHPUnit\Framework\TestCase;
use Tests\stub\app\middleware\AuthMiddleware;

class AuthMiddlewareTest extends TestCase
{
    public function test_passes_when_token_present(): void
    {
        $middleware = new AuthMiddleware();
        $request    = new Request(
            method: 'GET',
            path: '/',
            query: [],
            body: [],
            headers: ['X-Token' => 'valid-token'],
        );

        $expected = new Response('ok', 200);
        $next     = fn (Request $_req): Response => $expected;

        $response = $middleware->handle($request, $next);

        $this->assertSame($expected, $response);
    }

    public function test_throws_401_when_token_missing(): void
    {
        $middleware = new AuthMiddleware();
        $request    = new Request(
            method: 'GET',
            path: '/',
            query: [],
            body: [],
            headers: [],
        );

        $next = fn (Request $_req): Response => new Response('should not reach');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Unauthorized.');

        try {
            $middleware->handle($request, $next);
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());

            throw $e;
        }
    }

    public function test_throws_401_when_token_empty(): void
    {
        $middleware = new AuthMiddleware();
        $request    = new Request(
            method: 'GET',
            path: '/',
            query: [],
            body: [],
            headers: ['X-Token' => ''],
        );

        $next = fn (Request $_req): Response => new Response('should not reach');

        $this->expectException(HttpException::class);

        $middleware->handle($request, $next);
    }
}
