<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\auth\SaToken;
use PHPUnit\Framework\TestCase;

class SaTokenTest extends TestCase
{
    private Application $app;
    private SaToken $saToken;

    protected function setUp(): void
    {
        $this->app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $this->saToken = $this->app->container->get(SaToken::class);
    }

    public function test_login_returns_token(): void
    {
        $token = $this->saToken->login(1001);

        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function test_is_login_returns_true_after_login(): void
    {
        $token = $this->saToken->login(1001);

        $this->assertTrue($this->saToken->isLogin($token));
        $this->assertSame(1001, $this->saToken->getCurrentLoginId($token));
    }

    public function test_logout_invalidates_token(): void
    {
        $token = $this->saToken->login(1001);
        $this->assertTrue($this->saToken->isLogin($token));

        $this->saToken->logout($token);
        $this->assertFalse($this->saToken->isLogin($token));
    }

    public function test_invalid_token_is_not_logged_in(): void
    {
        $this->assertFalse($this->saToken->isLogin('invalid-token'));
    }
}
