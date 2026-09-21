<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\auth\HeaderTokenReader;
use PHPUnit\Framework\TestCase;

class HeaderTokenReaderTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['HTTP_X_TOKEN']);
        unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    public function test_reads_bearer_token_from_authorization_header(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer 5c7d77f3-57a6-4037-8c31-776b53a6c692';

        $reader = new HeaderTokenReader();
        $this->assertSame('5c7d77f3-57a6-4037-8c31-776b53a6c692', $reader->read());
    }

    public function test_bearer_token_is_case_insensitive_for_scheme(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'bearer 5c7d77f3-57a6-4037-8c31-776b53a6c692';

        $reader = new HeaderTokenReader();
        $this->assertSame('5c7d77f3-57a6-4037-8c31-776b53a6c692', $reader->read());
    }

    public function test_authorization_without_bearer_prefix_returns_null(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = '5c7d77f3-57a6-4037-8c31-776b53a6c692';

        $reader = new HeaderTokenReader();
        $this->assertNull($reader->read());
    }

    public function test_custom_token_header_takes_priority_over_authorization(): void
    {
        $_SERVER['HTTP_X_TOKEN']       = 'custom-token-value';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer 5c7d77f3-57a6-4037-8c31-776b53a6c692';

        $reader = new HeaderTokenReader('X-Token');
        $this->assertSame('custom-token-value', $reader->read());
    }

    public function test_redirect_http_authorization_fallback(): void
    {
        // Apache + mod_rewrite 场景
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer 5c7d77f3-57a6-4037-8c31-776b53a6c692';

        $reader = new HeaderTokenReader();
        $this->assertSame('5c7d77f3-57a6-4037-8c31-776b53a6c692', $reader->read());
    }

    public function test_no_authorization_header_returns_null(): void
    {
        $reader = new HeaderTokenReader();
        $this->assertNull($reader->read());
    }
}
