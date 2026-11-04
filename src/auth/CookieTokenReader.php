<?php

declare(strict_types=1);

namespace Lychee\auth;

use Lychee\container\Container;

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
        $request = Container::getInstance()->get('request');
        $value   = $request->cookie($this->tokenName);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
