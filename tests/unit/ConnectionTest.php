<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\Application;
use Lychee\auth\SaToken;
use Lychee\container\Container;
use Lychee\websocket\Connection;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    private Container $app;
    private SaToken $saToken;

    protected function setUp(): void
    {
        $application = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $this->app     = $application->container;
        $this->saToken = $this->app->get(SaToken::class);
    }

    private function createRawConnection(?string $token): object
    {
        return new class ($token) {
            public int $id = 1;
            public ?string $websocketToken;
            public string $websocketPath = '/chat';
            public array $websocketQuery = [];

            public function __construct(?string $token)
            {
                $this->websocketToken = $token;
            }

            public function send(string $data): void
            {
            }

            public function close(): void
            {
            }

            public function getRemoteIp(): string
            {
                return '127.0.0.1';
            }

            public function getRemotePort(): int
            {
                return 12345;
            }
        };
    }

    public function test_get_token_returns_handshake_token(): void
    {
        $raw  = $this->createRawConnection('the-token');
        $conn = new Connection($raw, $this->app);

        $this->assertSame('the-token', $conn->getToken());
    }

    public function test_get_token_returns_null_when_not_provided(): void
    {
        $raw  = $this->createRawConnection(null);
        $conn = new Connection($raw, $this->app);

        $this->assertNull($conn->getToken());
    }

    public function test_get_login_id_returns_login_id_for_valid_token(): void
    {
        $token = $this->saToken->login(7777, ['role' => 'admin']);

        $raw  = $this->createRawConnection($token);
        $conn = new Connection($raw, $this->app);

        $this->assertSame(7777, $conn->getLoginId());
    }

    public function test_get_login_id_returns_null_for_invalid_token(): void
    {
        $raw  = $this->createRawConnection('not-a-valid-token');
        $conn = new Connection($raw, $this->app);

        $this->assertNull($conn->getLoginId());
    }

    public function test_get_login_id_returns_null_without_token(): void
    {
        $raw  = $this->createRawConnection(null);
        $conn = new Connection($raw, $this->app);

        $this->assertNull($conn->getLoginId());
    }

    public function test_get_login_extra_returns_extra_data(): void
    {
        $token = $this->saToken->login(8888, ['role' => 'admin', 'dept' => 'tech']);

        $raw  = $this->createRawConnection($token);
        $conn = new Connection($raw, $this->app);

        $extra = $conn->getLoginExtra();

        $this->assertSame('admin', $extra['role']);
        $this->assertSame('tech', $extra['dept']);
    }

    public function test_get_login_extra_returns_empty_for_invalid_token(): void
    {
        $raw  = $this->createRawConnection('bad-token');
        $conn = new Connection($raw, $this->app);

        $this->assertSame([], $conn->getLoginExtra());
    }
}
