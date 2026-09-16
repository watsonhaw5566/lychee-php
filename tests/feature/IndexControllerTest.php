<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\http\Kernel;
use Lychee\http\Request;
use PHPUnit\Framework\TestCase;

class IndexControllerTest extends TestCase
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

    public function test_index_returns_hello_message(): void
    {
        $request = new Request(
            method: 'GET',
            path: '/',
            query: [],
            body: [],
            headers: [],
        );

        $response = $this->kernel->handle($request);

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $data = json_decode($response->content, true);
        $this->assertSame('Hello, Lychee PHP!', $data['message']);
    }

    public function test_unknown_route_returns_404(): void
    {
        $request = new Request(
            method: 'GET',
            path: '/non-existent',
            query: [],
            body: [],
            headers: [],
        );

        $response = $this->kernel->handle($request);

        $this->assertSame(404, $response->status);
    }
}
