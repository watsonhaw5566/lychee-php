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

    public function test_with_token_sets_context_for_parameterless_calls(): void
    {
        $token = $this->saToken->login(2002);

        // 闭包内未显式传 token，应自动使用 withToken 设置的上下文 token
        $loginId = $this->saToken->withToken($token, function () {
            return $this->saToken->getCurrentLoginId();
        });

        $this->assertSame(2002, $loginId);
    }

    public function test_with_token_restores_context_after_callback(): void
    {
        $token = $this->saToken->login(3003);

        $this->saToken->withToken($token, function (): void {
            $this->assertSame(3003, $this->saToken->getCurrentLoginId());
        });

        // 闭包结束后上下文恢复，无 token 时应抛 NotLoginException
        $this->expectException(\Lychee\auth\exception\NotLoginException::class);
        $this->saToken->getCurrentLoginId();
    }

    public function test_with_null_token_prevents_fallback_to_request(): void
    {
        // withToken(null) 表示"无 token"，不应回退到 TokenReader 读取请求
        $result = $this->saToken->withToken(null, function () {
            return $this->saToken->isLogin();
        });

        $this->assertFalse($result);
    }
}
