<?php

declare(strict_types=1);

namespace Lychee\http\client;

use JsonSerializable;

/**
 * HTTP 客户端响应。
 *
 * 封装 cURL 返回的响应数据，提供便捷的读取方法。
 */
class ClientResponse implements JsonSerializable
{
    /** @var array<string, string> */
    protected array $headers = [];

    public function __construct(
        protected string $body,
        protected int $statusCode,
        array $headers = [],
    ) {
        // 统一为小写键，便于不区分大小写的查找
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    /**
     * 获取响应体原始字符串。
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * 将响应体解码为数组。
     *
     * @return array<string, mixed>
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        $data = json_decode($this->body, true) ?? [];

        if ($key === null) {
            return $data;
        }

        return $data[$key] ?? $default;
    }

    /**
     * 获取 HTTP 状态码。
     */
    public function status(): int
    {
        return $this->statusCode;
    }

    /**
     * 获取指定响应头（不区分大小写）。
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * 获取全部响应头。
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function ok(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function successful(): bool
    {
        return $this->ok();
    }

    public function failed(): bool
    {
        return $this->statusCode >= 400;
    }

    public function clientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    public function serverError(): bool
    {
        return $this->statusCode >= 500;
    }

    /**
     * 失败时抛出异常，成功时返回自身。
     *
     * @throws RequestException
     */
    public function throw(): static
    {
        if ($this->failed()) {
            throw new RequestException($this);
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->body;
    }

    public function jsonSerialize(): mixed
    {
        return $this->json();
    }
}
