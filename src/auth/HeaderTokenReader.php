<?php

declare(strict_types=1);

namespace Lychee\auth;

/**
 * 从 HTTP Header 读取 token。
 *
 * 读取顺序：
 *   1. 自定义 header（由 token_name 配置指定）
 *   2. Authorization: Bearer xxx
 */
class HeaderTokenReader implements TokenReaderInterface
{
    public function __construct(
        protected string $tokenName = '',
    ) {
    }

    public function read(): ?string
    {
        if ($this->tokenName !== '') {
            $value = $this->getHeader($this->tokenName);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $authorization = $this->getHeader('Authorization');
        if (is_string($authorization) && $authorization !== '') {
            return preg_match('/^Bearer\s+(\S+)$/i', $authorization, $m) === 1 ? (string) $m[1] : null;
        }

        return null;
    }

    protected function getHeader(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return $_SERVER[$key] ?? null;
    }
}
