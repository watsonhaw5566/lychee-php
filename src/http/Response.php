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
     * 创建一个文件下载响应。
     *
     * 若 $file 为相对路径，则相对于 public 目录解析。
     *
     * @param  string               $file    文件路径（相对 public 或绝对路径）
     * @param  string|null          $name    下载时展示的文件名，为 null 时使用原文件名
     * @param  array<string,string> $headers 额外响应头
     *
     * @throws HttpException 文件不存在时抛出 404
     */
    public static function download(string $file, ?string $name = null, array $headers = []): static
    {
        $path = self::resolveDownloadPath($file);

        if (!is_file($path)) {
            throw new HttpException(404, "File not found: {$file}");
        }

        $filename = $name ?? basename($path);
        $content  = (string) file_get_contents($path);

        $headers['Content-Type']              = $headers['Content-Type'] ?? mime_content_type($path) ?: 'application/octet-stream';
        $headers['Content-Length']            = (string) filesize($path);
        $headers['Content-Disposition']       = 'attachment; filename="' . $filename . '"';
        $headers['Content-Transfer-Encoding'] = 'binary';
        $headers['Cache-Control']             = 'must-revalidate';
        $headers['Pragma']                    = 'public';

        return new static($content, 200, $headers);
    }

    /**
     * 创建一个重定向响应。
     *
     * @param  string               $url     目标 URL
     * @param  int                  $status  HTTP 状态码（默认 302）
     * @param  array<string,string> $headers 额外响应头
     */
    public static function redirect(string $url, int $status = 302, array $headers = []): static
    {
        $headers['Location'] = $url;

        return new static('', $status, $headers);
    }

    /**
     * 解析下载文件的绝对路径。
     *
     * 相对路径基于 public 目录解析；绝对路径直接使用。
     */
    private static function resolveDownloadPath(string $file): string
    {
        if (self::isAbsolute($file)) {
            return $file;
        }

        // path.public 已以目录分隔符结尾
        return app('path.public') . ltrim($file, '/\\');
    }

    private static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // Windows 盘符路径，如 C:\ 或 C:/
        if (preg_match('/^[a-zA-Z]:[\/\\\\]/', $path) === 1) {
            return true;
        }

        return str_starts_with($path, '/') || str_starts_with($path, DIRECTORY_SEPARATOR);
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
