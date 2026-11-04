<?php

declare(strict_types=1);

namespace Lychee\auth;

use Lychee\container\Container;

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

    /**
     * 大小写不敏感地从 Request 对象读取请求头。
     */
    protected function getHeader(string $name): ?string
    {
        $request = Container::getInstance()->get('request');

        return $request->header($name);
    }
}
