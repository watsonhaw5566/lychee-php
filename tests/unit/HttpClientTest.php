<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\http\client\ClientResponse;
use Lychee\http\client\HttpClient;
use Lychee\http\client\PendingRequest;
use Lychee\http\client\RequestException;
use PHPUnit\Framework\TestCase;

class HttpClientTest extends TestCase
{
    // ── ClientResponse ──────────────────────────────────────────────

    public function test_response_status_helpers(): void
    {
        $ok       = new ClientResponse('', 200);
        $notFound = new ClientResponse('', 404);
        $error    = new ClientResponse('', 500);

        $this->assertTrue($ok->ok());
        $this->assertTrue($ok->successful());
        $this->assertFalse($ok->failed());

        $this->assertTrue($notFound->clientError());
        $this->assertFalse($notFound->serverError());

        $this->assertTrue($error->serverError());
        $this->assertTrue($error->failed());
    }

    public function test_response_json_parsing(): void
    {
        $body     = json_encode(['name' => 'test', 'age' => 25]);
        $response = new ClientResponse($body, 200);

        $this->assertSame(['name' => 'test', 'age' => 25], $response->json());
        $this->assertSame('test', $response->json('name'));
        $this->assertSame(25, $response->json('age'));
        $this->assertNull($response->json('missing'));
        $this->assertSame('default', $response->json('missing', 'default'));
    }

    public function test_response_headers_case_insensitive(): void
    {
        $response = new ClientResponse('', 200, [
            'Content-Type' => 'application/json',
            'X-Request-Id' => 'abc-123',
        ]);

        $this->assertSame('application/json', $response->header('content-type'));
        $this->assertSame('abc-123', $response->header('x-request-id'));
        $this->assertNull($response->header('missing'));
        $this->assertCount(2, $response->headers());
    }

    public function test_response_body_and_string_cast(): void
    {
        $response = new ClientResponse('hello world', 200);

        $this->assertSame('hello world', $response->body());
        $this->assertSame('hello world', (string) $response);
    }

    public function test_response_throw_on_failure(): void
    {
        $ok = new ClientResponse('', 200);
        $this->assertSame($ok, $ok->throw());

        $this->expectException(RequestException::class);
        $this->expectExceptionCode(500);

        $error = new ClientResponse('server error', 500);
        $error->throw();
    }

    public function test_exception_carries_response(): void
    {
        $response = new ClientResponse('bad', 422);

        try {
            $response->throw();
            $this->fail('Expected RequestException');
        } catch (RequestException $e) {
            $this->assertSame($response, $e->getResponse());
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('422', $e->getMessage());
        }
    }

    // ── PendingRequest 构建器 ───────────────────────────────────────

    public function test_create_returns_pending_request(): void
    {
        $this->assertInstanceOf(PendingRequest::class, HttpClient::create());
    }

    public function test_http_helper_returns_pending_request(): void
    {
        $this->assertInstanceOf(PendingRequest::class, http());
    }

    public function test_chain_methods_return_self(): void
    {
        $request = new PendingRequest();

        $this->assertSame($request, $request->baseUrl('https://example.com'));
        $this->assertSame($request, $request->withHeaders(['X-Foo' => 'bar']));
        $this->assertSame($request, $request->withHeader('X-Bar', 'baz'));
        $this->assertSame($request, $request->withToken('token'));
        $this->assertSame($request, $request->withBasicAuth('user', 'pass'));
        $this->assertSame($request, $request->withQuery(['page' => '1']));
        $this->assertSame($request, $request->timeout(5));
        $this->assertSame($request, $request->connectTimeout(3));
        $this->assertSame($request, $request->retry(2, 50));
        $this->assertSame($request, $request->withoutVerifying());
        $this->assertSame($request, $request->asForm());
        $this->assertSame($request, $request->asJson());
        $this->assertSame($request, $request->asMultipart());
    }

    // ── 真实 HTTP 请求（需要网络） ──────────────────────────────────

    public function test_real_get_request(): void
    {
        $response = HttpClient::get('https://httpbin.org/get', ['foo' => 'bar']);

        $this->assertTrue($response->ok());
        $this->assertSame(200, $response->status());
        $this->assertSame('bar', $response->json('args')['foo'] ?? null);
    }

    public function test_real_post_json(): void
    {
        $response = HttpClient::post('https://httpbin.org/post', ['name' => 'lychee']);

        $this->assertTrue($response->ok());
        $json = $response->json('json');
        $this->assertSame('lychee', $json['name'] ?? null);
    }

    public function test_real_post_form(): void
    {
        $response = http()
            ->asForm()
            ->post('https://httpbin.org/post', ['key' => 'value']);

        $this->assertTrue($response->ok());
        $form = $response->json('form');
        $this->assertSame('value', $form['key'] ?? null);
    }

    public function test_real_bearer_token(): void
    {
        $response = http()
            ->withToken('my-secret-token')
            ->get('https://httpbin.org/headers');

        $this->assertTrue($response->ok());
        $headers = $response->json('headers');
        $this->assertSame('Bearer my-secret-token', $headers['Authorization'] ?? null);
    }
}
