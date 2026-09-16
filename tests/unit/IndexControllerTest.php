<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\http\JsonResponse;
use PHPUnit\Framework\TestCase;
use Tests\stub\app\controller\IndexController;

class IndexControllerTest extends TestCase
{
    public function test_index_returns_json_with_hello_message(): void
    {
        $controller = new IndexController();

        $response = $controller->index();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->status);

        $data = json_decode($response->content, true);
        $this->assertSame('Hello, Lychee PHP!', $data['message']);
    }
}
