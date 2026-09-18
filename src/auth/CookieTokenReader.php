<?php

declare(strict_types=1);

namespace Lychee\auth;

/**
 * 从 Cookie 读取 token。
 *
 * 适用于传统服务端渲染页面，浏览器跳转自动携带 cookie。
 */
class CookieTokenReader implements TokenReaderInterface
{
    public function __construct(
        protected string $tokenName = 'satoken',
    ) {
    }

    public function read(): ?string
    {
        $value = $_COOKIE[$this->tokenName] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
