<?php

declare(strict_types=1);

namespace Lychee\i18n;

use Closure;
use Lychee\http\MiddlewareInterface;
use Lychee\http\Request;
use Lychee\http\Response;

/**
 * 语言检测中间件。
 *
 * 在请求处理前根据以下优先级解析并设置当前语言：
 *   1. 查询参数（默认 lang）
 *   2. Cookie
 *   3. Accept-Language 请求头（需配置开启）
 *
 * 解析出的语言会写入 I18n 实例，并通过 Cookie 回写给客户端以便下次请求复用。
 */
class I18nMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly I18n $i18n,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        if ($locale !== '') {
            $this->i18n->setLocale($locale);
        }

        $response = $next($request);

        // 把当前语言写入 Cookie，便于后续请求复用
        if ($locale !== '') {
            $response->cookie('lang', $locale, minutes: 60 * 24 * 30);
        }

        return $response;
    }

    /**
     * 从请求中解析语言。
     */
    private function resolveLocale(Request $request): string
    {
        // 1. 查询参数
        $query = $request->get('lang');
        if (is_string($query) && $query !== '') {
            return $this->normalize($query);
        }

        // 2. Cookie
        $cookie = $request->cookie('lang');
        if (is_string($cookie) && $cookie !== '') {
            return $this->normalize($cookie);
        }

        // 3. Accept-Language 请求头
        $header = $request->header('Accept-Language');
        if (is_string($header) && $header !== '') {
            return $this->parseAcceptLanguage($header);
        }

        return '';
    }

    /**
     * 规范化语言标识（仅保留字母、数字、连字符、下划线）。
     */
    private function normalize(string $locale): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $locale) ?? '';
    }

    /**
     * 从 Accept-Language 头中取优先级最高的语言。
     *
     * 例如 `zh-CN,zh;q=0.9,en;q=0.8` 返回 `zh-CN`。
     */
    private function parseAcceptLanguage(string $header): string
    {
        $parts = explode(',', $header);

        $best  = '';
        $bestQ = -1.0;

        foreach ($parts as $part) {
            $segments = explode(';', trim($part), 2);
            $lang     = $this->normalize(trim($segments[0]));
            $q        = 1.0;

            if (isset($segments[1]) && preg_match('/q=([0-9.]+)/', $segments[1], $m)) {
                $q = (float) $m[1];
            }

            if ($lang !== '' && $q > $bestQ) {
                $best  = $lang;
                $bestQ = $q;
            }
        }

        return $best;
    }
}