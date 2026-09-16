<?php

declare(strict_types=1);

namespace Lychee\http;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * HTTP Cookie 值对象。
 *
 * 封装 Set-Cookie 头的所有属性，支持 Secure / HttpOnly / SameSite 等安全选项。
 */
class Cookie
{
    public const SAME_SITE_LAX    = 'Lax';
    public const SAME_SITE_STRICT = 'Strict';
    public const SAME_SITE_NONE   = 'None';

    public function __construct(
        public readonly string $name,
        public readonly string $value = '',
        public readonly ?DateTimeInterface $expiresAt = null,
        public readonly ?int $maxAge = null,
        public readonly string $path = '/',
        public readonly ?string $domain = null,
        public readonly bool $secure = false,
        public readonly bool $httpOnly = true,
        public readonly ?string $sameSite = self::SAME_SITE_LAX,
    ) {
    }

    /**
     * 通过秒数设置过期时间（正数为未来，0 或负数为立即删除）。
     */
    public static function create(
        string $name,
        string $value = '',
        int $minutes = 0,
        string $path = '/',
        ?string $domain = null,
        bool $secure = false,
        bool $httpOnly = true,
        ?string $sameSite = self::SAME_SITE_LAX,
    ): self {
        $expiresAt = null;
        $maxAge    = null;

        if ($minutes !== 0) {
            $seconds   = $minutes * 60;
            $expiresAt = (new DateTimeImmutable())->add(new DateInterval('PT' . abs($seconds) . 'S'));
            if ($seconds < 0) {
                $expiresAt = (new DateTimeImmutable())->sub(new DateInterval('PT' . abs($seconds) . 'S'));
            }
            $maxAge = $seconds;
        }

        return new self(
            name: $name,
            value: $value,
            expiresAt: $expiresAt,
            maxAge: $maxAge,
            path: $path,
            domain: $domain,
            secure: $secure,
            httpOnly: $httpOnly,
            sameSite: $sameSite,
        );
    }

    /**
     * 创建一个用于删除 cookie 的实例（过期时间设为过去）。
     */
    public static function forget(string $name, string $path = '/', ?string $domain = null): self
    {
        return self::create(
            name: $name,
            value: '',
            minutes: -2628000,
            path: $path,
            domain: $domain,
        );
    }

    /**
     * 序列化为 Set-Cookie 头字符串。
     */
    public function __toString(): string
    {
        $parts = [rawurlencode($this->name) . '=' . rawurlencode($this->value)];

        if ($this->expiresAt !== null) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', $this->expiresAt->getTimestamp());
        }

        if ($this->maxAge !== null) {
            $parts[] = 'Max-Age=' . $this->maxAge;
        }

        if ($this->path !== '') {
            $parts[] = 'Path=' . $this->path;
        }

        if ($this->domain !== null && $this->domain !== '') {
            $parts[] = 'Domain=' . $this->domain;
        }

        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        if ($this->sameSite !== null) {
            $parts[] = 'SameSite=' . $this->sameSite;
        }

        return implode('; ', $parts);
    }
}
