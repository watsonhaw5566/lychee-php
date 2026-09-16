<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\http\Cookie;
use PHPUnit\Framework\TestCase;

class CookieTest extends TestCase
{
    public function test_cookie_string_with_all_attributes(): void
    {
        $cookie = Cookie::create(
            name: 'token',
            value: 'abc123',
            minutes: 60,
            path: '/',
            domain: 'example.com',
            secure: true,
            httpOnly: true,
            sameSite: Cookie::SAME_SITE_STRICT,
        );

        $header = (string) $cookie;

        $this->assertStringContainsString('token=abc123', $header);
        $this->assertStringContainsString('Path=/', $header);
        $this->assertStringContainsString('Domain=example.com', $header);
        $this->assertStringContainsString('Secure', $header);
        $this->assertStringContainsString('HttpOnly', $header);
        $this->assertStringContainsString('SameSite=Strict', $header);
        $this->assertStringContainsString('Max-Age=3600', $header);
        $this->assertStringContainsString('Expires=', $header);
    }

    public function test_forget_cookie_has_negative_max_age(): void
    {
        $cookie = Cookie::forget('token');

        $header = (string) $cookie;

        $this->assertStringContainsString('token=', $header);
        $this->assertStringContainsString('Max-Age=-157680000', $header);
    }

    public function test_session_cookie_has_no_expires(): void
    {
        $cookie = Cookie::create(name: 'sid', value: 'xyz');

        $header = (string) $cookie;

        $this->assertStringNotContainsString('Expires=', $header);
        $this->assertStringNotContainsString('Max-Age=', $header);
    }

    public function test_same_site_none_requires_secure(): void
    {
        $cookie = Cookie::create(
            name: 'sid',
            value: 'xyz',
            secure: true,
            sameSite: Cookie::SAME_SITE_NONE,
        );

        $header = (string) $cookie;

        $this->assertStringContainsString('SameSite=None', $header);
        $this->assertStringContainsString('Secure', $header);
    }
}
