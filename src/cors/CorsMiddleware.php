<?php

declare(strict_types=1);

namespace Lychee\cors;

use Closure;
use Lychee\http\MiddlewareInterface;
use Lychee\http\Request;
use Lychee\http\Response;

/**
 * 跨域资源共享（CORS）中间件。
 *
 * 处理两类跨域请求：
 *   1. 预检请求（OPTIONS + Access-Control-Request-Method）：直接返回允许策略，不进入控制器
 *   2. 实际跨域请求：在响应上补充 Access-Control-Allow-Origin 等头
 *
 * 全局默认配置读取自 config/cors.php，子类可通过构造参数覆盖：
 *
 *   class AdminCors extends CorsMiddleware
 *   {
 *       public function __construct()
 *       {
 *           parent::__construct(['allowed_origins' => ['https://admin.example.com']]);
 *       }
 *   }
 *
 * 建议注册为全局中间件（config/middleware.php），以覆盖所有路由（含未注册 OPTIONS 路由的预检）。
 */
class CorsMiddleware implements MiddlewareInterface
{
    /** @var array<string, mixed> 默认配置 */
    private const DEFAULTS = [
        // 允许的源；'*' 允许所有，支持通配符（如 https://*.example.com）
        'allowed_origins'     => ['*'],

        // 允许的 HTTP 方法
        'allowed_methods'     => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

        // 允许的请求头；['*'] 时在凭证模式下回显 Access-Control-Request-Headers
        'allowed_headers'     => ['Content-Type', 'Authorization', 'X-Requested-With'],

        // 允许浏览器读取的自定义响应头
        'exposed_headers'     => [],

        // 是否允许携带凭证（Cookie）；开启时 Allow-Origin 不能为 *，将回显具体源
        'allowed_credentials' => false,

        // 预检结果缓存时间（秒），0 表示不发送该头
        'max_age'             => 86400,

        // 预检响应状态码
        'preflight_status'    => 204,
    ];

    /** @var array<string, mixed> */
    private readonly array $config;

    /**
     * @param array<string, mixed> $config 配置覆盖项，优先级高于 config/cors.php
     */
    public function __construct(array $config = [])
    {
        /** @var array<string, mixed> $global */
        $global = config('cors', []);

        $this->config = array_merge(self::DEFAULTS, $global, $config);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $origin = (string) $request->header('Origin', '');

        // 非跨域请求（同源 / 非浏览器客户端）或源未被允许：不添加任何 CORS 头
        if ($origin === '' || !$this->isOriginAllowed($origin)) {
            return $next($request);
        }

        // 预检请求：直接返回允许策略，不进入控制器
        if ($this->isPreflight($request)) {
            return $this->buildPreflightResponse($request, $origin);
        }

        $response = $next($request);

        $this->withOriginHeaders($response, $origin);

        $exposed = $this->normalizeList($this->config['exposed_headers']);
        if ($exposed !== []) {
            $response->withHeader('Access-Control-Expose-Headers', implode(', ', $exposed));
        }

        return $response;
    }

    /**
     * 判断是否为 CORS 预检请求（OPTIONS 且携带 Access-Control-Request-Method）。
     */
    private function isPreflight(Request $request): bool
    {
        return $request->method === 'OPTIONS'
            && $request->header('Access-Control-Request-Method', '') !== '';
    }

    /**
     * 构造预检响应。
     */
    private function buildPreflightResponse(Request $request, string $origin): Response
    {
        $response = new Response('', (int) $this->config['preflight_status']);

        $this->withOriginHeaders($response, $origin);

        $response->withHeader(
            'Access-Control-Allow-Methods',
            implode(', ', $this->normalizeList($this->config['allowed_methods'], uppercase: true))
        );

        $response->withHeader('Access-Control-Allow-Headers', $this->resolveAllowedHeaders($request));

        $maxAge = (int) $this->config['max_age'];
        if ($maxAge > 0) {
            $response->withHeader('Access-Control-Max-Age', (string) $maxAge);
        }

        return $response;
    }

    /**
     * 写入与源相关的响应头（Allow-Origin / Allow-Credentials / Vary）。
     *
     * 规范要求携带凭证时 Allow-Origin 不能为 '*'，此时回显具体源并附带 Vary: Origin；
     * 白名单模式下响应随 Origin 变化，同样需要 Vary: Origin。
     */
    private function withOriginHeaders(Response $response, string $origin): void
    {
        $credentials = (bool) $this->config['allowed_credentials'];
        $wildcard    = in_array('*', $this->normalizeList($this->config['allowed_origins']), true);

        if ($wildcard && !$credentials) {
            $response->withHeader('Access-Control-Allow-Origin', '*');

            return;
        }

        $response->withHeader('Access-Control-Allow-Origin', $origin);
        $response->withHeader('Vary', 'Origin');

        if ($credentials) {
            $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }
    }

    /**
     * 解析 Allow-Headers 头值。
     *
     * - 配置为具体列表时返回列表
     * - 配置含 '*' 时：凭证模式不能使用通配符，回显预检请求的 Access-Control-Request-Headers；
     *   请求未携带该头时回退为 '*'
     */
    private function resolveAllowedHeaders(Request $request): string
    {
        $configured = $this->normalizeList($this->config['allowed_headers']);

        if (in_array('*', $configured, true)) {
            $echo = (string) $request->header('Access-Control-Request-Headers', '');

            return $echo !== '' ? $echo : '*';
        }

        return implode(', ', $configured);
    }

    /**
     * 校验源是否被允许：支持精确匹配与 * 通配符（如 https://*.example.com）。
     */
    private function isOriginAllowed(string $origin): bool
    {
        foreach ($this->normalizeList($this->config['allowed_origins']) as $pattern) {
            if ($pattern === '*' || $pattern === $origin) {
                return true;
            }

            if (!str_contains($pattern, '*')) {
                continue;
            }

            $regex = '#^' . str_replace('\\*', '.*', preg_quote($pattern, '#')) . '$#';

            if (preg_match($regex, $origin) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 规范化配置中的列表值（去空白、去空项）。
     *
     * @return list<string>
     */
    private function normalizeList(mixed $values, bool $uppercase = false): array
    {
        $list = [];

        foreach ((array) $values as $value) {
            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $list[] = $uppercase ? strtoupper($value) : $value;
        }

        return $list;
    }
}
