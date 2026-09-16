<?php

declare(strict_types=1);

namespace Lychee\http;

/**
 * HTTP 响应基类。
 */
class Response
{
    /** @var Cookie[] */
    protected array $cookies = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $content = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    /**
     * 设置一个 Cookie。
     *
     * @param  string         $name     Cookie 名称
     * @param  string         $value    Cookie 值
     * @param  int            $minutes  过期分钟数（0 为会话级，负数为删除）
     * @param  string         $path     路径
     * @param  string|null    $domain   域名
     * @param  bool           $secure   仅 HTTPS 传输
     * @param  bool           $httpOnly 禁止 JS 访问
     * @param  string|null    $sameSite SameSite 策略
     */
    public function cookie(
        string $name,
        string $value = '',
        int $minutes = 0,
        string $path = '/',
        ?string $domain = null,
        bool $secure = false,
        bool $httpOnly = true,
        ?string $sameSite = Cookie::SAME_SITE_LAX,
    ): static {
        $this->cookies[$name] = Cookie::create(
            name: $name,
            value: $value,
            minutes: $minutes,
            path: $path,
            domain: $domain,
            secure: $secure,
            httpOnly: $httpOnly,
            sameSite: $sameSite,
        );

        return $this;
    }

    /**
     * 添加一个 Cookie 实例。
     */
    public function withCookie(Cookie $cookie): static
    {
        $this->cookies[$cookie->name] = $cookie;

        return $this;
    }

    /**
     * 删除一个 Cookie。
     */
    public function withoutCookie(string $name, string $path = '/', ?string $domain = null): static
    {
        $this->cookies[$name] = Cookie::forget($name, $path, $domain);

        return $this;
    }

    /**
     * 获取所有待发送的 Cookie。
     *
     * @return Cookie[]
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        foreach ($this->cookies as $cookie) {
            header('Set-Cookie: ' . (string) $cookie, false);
        }

        echo $this->content;
    }
}
