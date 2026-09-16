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

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        private readonly array $cookies = [],
        public readonly string $rawBody = '',
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
        );
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body, $this->routeParams);
    }

    /**
     * 获取请求变量（兼容 ThinkPHP，合并 query / body / 路由参数）
     *
     * @param  string|null $name    变量名，为 null 时返回全部
     * @param  mixed       $default 默认值
     * @return mixed
     */
    public function param(?string $name = null, mixed $default = null): mixed
    {
        $data = $this->all();

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
        return array_key_exists($name, $this->all());
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
