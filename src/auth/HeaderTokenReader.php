<?php

declare(strict_types=1);

namespace Lychee\auth;

/**
 * 从 HTTP Header 读取 token。
 *
 * 读取顺序：
 *   1. 自定义 header（由 token_name 配置指定）
 *   2. Authorization: Bearer xxx
 *
 * header 读取优先使用 getallheaders()（Apache/nginx 均可正确返回 Authorization），
 * 回退到 $_SERVER 中 HTTP_ 前缀的键，兼容各类运行环境。
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
     * 大小写不敏感地读取请求头。
     *
     * 优先通过 getallheaders() 获取完整 header 列表，
     * 回退到 $_SERVER 的 HTTP_ 前缀键（Apache 下 Authorization 有时
     * 会出现在 REDIRECT_HTTP_AUTHORIZATION 中，一并兼容）。
     */
    protected function getHeader(string $name): ?string
    {
        $lower = strtolower($name);

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $key => $value) {
                    if (strtolower((string) $key) === $lower) {
                        return is_string($value) ? $value : null;
                    }
                }
            }
        }

        // 回退：$_SERVER 的 HTTP_ 前缀键
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
            return $_SERVER[$key];
        }

        // Apache + mod_rewrite 场景下，Authorization 可能在 REDIRECT_HTTP_AUTHORIZATION
        if ($lower === 'authorization') {
            foreach (['REDIRECT_HTTP_AUTHORIZATION', 'REDIRECT_AUTHORIZATION'] as $fallback) {
                if (isset($_SERVER[$fallback]) && is_string($_SERVER[$fallback])) {
                    return $_SERVER[$fallback];
                }
            }
        }

        return null;
    }
}
