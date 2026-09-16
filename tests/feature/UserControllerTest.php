<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\http\Kernel;
use Lychee\http\Request;
use PHPUnit\Framework\TestCase;
use think\DbManager;

class UserControllerTest extends TestCase
{
    private Kernel $kernel;
    private DbManager $db;

    protected function setUp(): void
    {
        $app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $this->kernel = $app->container->get(Kernel::class);
        $this->db     = $app->container->get('db');

        // 在 sqlite 内存库中建表
        $this->db->execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            age INTEGER DEFAULT 0,
            create_time DATETIME,
            update_time DATETIME
        )');
    }

    public function test_index_returns_user_list(): void
    {
        $this->db->table('users')->insert(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30]);

        $response = $this->kernel->handle(new Request('GET', '/users', [], [], []));

        $this->assertSame(200, $response->status);
        $data = json_decode($response->content, true);
        $this->assertCount(1, $data['data']);
        $this->assertSame('Alice', $data['data'][0]['name']);
    }

    public function test_show_returns_user_when_found(): void
    {
        $id = $this->db->table('users')->insertGetId(['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 25]);

        $response = $this->kernel->handle(new Request('GET', "/users/{$id}", [], [], []));

        $this->assertSame(200, $response->status);
        $data = json_decode($response->content, true);
        $this->assertSame('Bob', $data['data']['name']);
    }

    public function test_show_returns_404_when_not_found(): void
    {
        $response = $this->kernel->handle(new Request('GET', '/users/999', [], [], []));

        $this->assertSame(404, $response->status);
        $data = json_decode($response->content, true);
        $this->assertSame('User not found.', $data['message']);
    }

    public function test_store_creates_user_successfully(): void
    {
        $response = $this->kernel->handle(new Request(
            method: 'POST',
            path: '/users',
            query: [],
            body: ['name' => 'Charlie', 'email' => 'charlie@example.com', 'age' => '28'],
            headers: [],
        ));

        $this->assertSame(201, $response->status);
        $data = json_decode($response->content, true);
        $this->assertSame('Charlie', $data['data']['name']);
        $this->assertSame('charlie@example.com', $data['data']['email']);
    }

    public function test_store_returns_400_when_validation_fails(): void
    {
        $response = $this->kernel->handle(new Request(
            method: 'POST',
            path: '/users',
            query: [],
            body: ['name' => '', 'email' => 'invalid-email'],
            headers: [],
        ));

        $this->assertSame(400, $response->status);
        $data = json_decode($response->content, true);
        $this->assertSame(400, $data['code']);
        $this->assertArrayHasKey('msg', $data);
    }
}
