<?php

declare(strict_types=1);

namespace Lychee\http;

/**
 * 强类型 HTTP 请求封装。
 */
class Request
{
    /** @var array<string, string> */
    private array $routeParams = [];

    /** 当前登录用户 ID（由认证中间件设置） */
    private ?int $loginId = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        public readonly array $cookies = [],
        public readonly string $rawBody = '',
        public readonly string $ip = '',
        public readonly string $scheme = 'http',
    ) {
    }

    public static function capture(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI']    ?? '/';
        $path   = parse_url($uri, PHP_URL_PATH) ?: '/';

        $headers = function_exists('getallheaders')
            ? getallheaders()
            : self::parseHeadersFromServer();

        $rawBody = file_get_contents('php://input') ?: '';
        $body    = self::parseBody($rawBody, $headers['Content-Type'] ?? '');

        return new self(
            method: $method,
            path: $path,
            query: $_GET,
            body: $body,
            headers: $headers,
            cookies: $_COOKIE,
            rawBody: $rawBody,
            ip: self::resolveIp($headers),
            scheme: self::resolveScheme($headers),
        );
    }

    /**
     * 获取 GET 查询参数。
     *
     * @param string|null $key     参数名，为 null 时返回全部查询参数
     * @param mixed       $default 默认值
     * @return array<string, mixed>|mixed
     */
    public function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    /**
     * 获取 POST 请求体参数。
     *
     * @param string|null $key     参数名，为 null 时返回全部请求体参数
     * @param mixed       $default 默认值
     * @return array<string, mixed>|mixed
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }

        return $this->body[$key] ?? $default;
    }

    /**
     * 获取请求方法。
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * 获取请求 URI 路径。
     */
    public function getUri(): string
    {
        return $this->path;
    }

    /**
     * 获取请求协议（http / https）。
     */
    public function scheme(): string
    {
        return $this->scheme;
    }

    /**
     * 获取请求主机名（含端口）。
     *
     * 反向代理场景优先读取 X-Forwarded-Host，否则回退到 Host 头。
     */
    public function host(): string
    {
        $host = $this->header('X-Forwarded-Host', '');
        if ($host !== '') {
            return $host;
        }

        return $this->header('Host', '');
    }

    /**
     * 获取当前域名（协议 + 主机），如 https://example.com。
     */
    public function domain(): string
    {
        $host = $this->host();
        if ($host === '') {
            return '';
        }

        return $this->scheme . '://' . $host;
    }

    /**
     * 判断是否为 GET 请求。
     */
    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    /**
     * 判断是否为 POST 请求。
     */
    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * 判断是否为 AJAX 请求。
     */
    public function isAjax(): bool
    {
        return $this->header('X-Requested-With', '') === 'XMLHttpRequest';
    }

    /**
     * 判断是否为移动设备（基于 User-Agent）。
     */
    public function isMobile(): bool
    {
        $ua = $this->header('User-Agent', '');

        if ($ua === '') {
            return false;
        }

        return (bool) preg_match(
            '/android|iphone|ipad|ipod|mobile|wap|webos|blackberry|windows phone|opera mini|iemobile/i',
            $ua
        );
    }

    /**
     * 获取请求变量（合并 query / body / 路由参数）
     *
     * @param  string|null $name    变量名，为 null 时返回全部
     * @param  mixed       $default 默认值
     * @return mixed
     */
    public function param(?string $name = null, mixed $default = null): mixed
    {
        $data = array_merge($this->query, $this->body, $this->routeParams);

        if (is_null($name)) {
            return $data;
        }

        return $data[$name] ?? $default;
    }

    /**
     * 判断请求变量是否存在
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, array_merge($this->query, $this->body, $this->routeParams));
    }

    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[$key] ?? $this->headers[strtolower($key)] ?? $default;
    }

    /**
     * 读取请求中的 Cookie 值。
     *
     * @param  string|null $name    Cookie 名称，为 null 时返回全部 Cookie
     * @param  mixed       $default 默认值
     */
    public function cookie(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->cookies;
        }

        return $this->cookies[$name] ?? $default;
    }

    /**
     * 判断请求中是否存在指定 Cookie。
     */
    public function hasCookie(string $name): bool
    {
        return array_key_exists($name, $this->cookies);
    }

    public function routeParam(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    // ── 认证相关 ────────────────────────────────────────────────────

    /**
     * 设置当前登录用户 ID（由认证中间件调用）。
     */
    public function setLoginId(int $loginId): void
    {
        $this->loginId = $loginId;
    }

    /**
     * 获取当前登录用户 ID。
     */
    public function loginId(): ?int
    {
        return $this->loginId;
    }

    /** @param array<string, string> $params */
    public function withRouteParams(array $params): self
    {
        $clone              = clone $this;
        $clone->routeParams = $params;

        return $clone;
    }

    public function wantsJson(): bool
    {
        $accept = $this->header('Accept', '');

        return str_contains($accept, 'application/json') || str_contains($accept, '*/*');
    }

    public function isJson(): bool
    {
        $type = $this->header('Content-Type', '');

        return str_contains($type, 'application/json');
    }

    private static function parseHeadersFromServer(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name           = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * 解析客户端 IP。
     *
     * 优先读取反向代理常用头（X-Real-IP / X-Forwarded-For），
     * 其次回退到 REMOTE_ADDR。
     *
     * @param  array<string, string> $headers
     */
    private static function resolveIp(array $headers): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        $map = [];
        foreach ($headers as $name => $value) {
            $map[strtolower($name)] = $value;
        }

        foreach (['x-real-ip', 'x-forwarded-for'] as $key) {
            if (isset($map[$key]) && $map[$key] !== '') {
                $ip = trim(explode(',', $map[$key])[0]);
                break;
            }
        }

        return $ip;
    }

    /**
     * 解析请求协议。
     *
     * 优先级：X-Forwarded-Proto 头 > $_SERVER['HTTPS'] > SERVER_PORT 443。
     *
     * @param  array<string, string> $headers
     */
    private static function resolveScheme(array $headers): string
    {
        $map = [];
        foreach ($headers as $name => $value) {
            $map[strtolower($name)] = $value;
        }

        if (isset($map['x-forwarded-proto']) && $map['x-forwarded-proto'] !== '') {
            return strtolower(trim(explode(',', $map['x-forwarded-proto'])[0]));
        }

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return 'https';
        }

        if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return 'https';
        }

        return 'http';
    }

    private static function parseBody(string $raw, string $contentType): array
    {
        if ($raw === '') {
            return $_POST;
        }

        if (str_contains($contentType, 'application/json')) {
            $data = json_decode($raw, true);

            return is_array($data) ? $data : [];
        }

        return $_POST;
    }
}