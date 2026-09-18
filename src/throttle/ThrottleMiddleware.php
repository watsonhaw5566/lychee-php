<?php

declare(strict_types=1);

namespace Lychee\throttle;

use Closure;
use Lychee\http\JsonResponse;
use Lychee\http\MiddlewareInterface;
use Lychee\http\Request;
use Lychee\http\Response;
use think\CacheManager;

/**
 * 限速中间件（Throttle）。
 *
 * 基于缓存的固定窗口计数器实现，支持按 IP / 用户 / 路由等多种维度限流。
 * 超出限额时返回 429 Too Many Requests，并附带 X-RateLimit-* 与 Retry-After 响应头。
 *
 * 全局默认配置读取自 config/throttle.php，子类可通过构造参数覆盖：
 *
 *   class LoginThrottle extends ThrottleMiddleware
 *   {
 *       public function __construct(CacheManager $cache)
 *       {
 *           parent::__construct($cache, ['max_attempts' => 5, 'decay_seconds' => 60]);
 *       }
 *   }
 */
class ThrottleMiddleware implements MiddlewareInterface
{
    /** @var array<string, mixed> 默认配置 */
    private const DEFAULTS = [
        'max_attempts'  => 60,
        'decay_seconds' => 60,
        'key_type'      => 'ip',
        'prefix'        => 'throttle:',
        'store'         => null,
        'with_headers'  => true,
        'message'       => '请求过于频繁，请稍后再试',
    ];

    private readonly CacheManager $cache;

    /** @var array<string, mixed> */
    private readonly array $config;

    /**
     * @param CacheManager        $cache  缓存管理器
     * @param array<string, mixed> $config 配置覆盖项，优先级高于 config/throttle.php
     */
    public function __construct(CacheManager $cache, array $config = [])
    {
        $this->cache = $cache;

        /** @var array<string, mixed> $global */
        $global = config('throttle', []);

        $this->config = array_merge(self::DEFAULTS, $global, $config);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $maxAttempts  = (int) $this->config['max_attempts'];
        $decaySeconds = (int) $this->config['decay_seconds'];
        $key          = $this->resolveKey($request);
        $store        = $this->store();

        $hits = (int) $store->get($key, 0);

        if ($hits >= $maxAttempts) {
            return $this->buildTooManyRequestsResponse($maxAttempts, $hits, $decaySeconds);
        }

        // 首次写入带 TTL，后续自增以保持窗口结束时间不变
        if ($hits === 0) {
            $store->set($key, 1, $decaySeconds);
        } else {
            $store->inc($key);
        }

        $response = $next($request);

        if ($this->config['with_headers']) {
            $response->withHeader('X-RateLimit-Limit', (string) $maxAttempts);
            $response->withHeader('X-RateLimit-Remaining', (string) max(0, $maxAttempts - $hits - 1));
            $response->withHeader('X-RateLimit-Reset', (string) (time() + $decaySeconds));
        }

        return $response;
    }

    /**
     * 根据 key_type 解析限流标识。
     */
    private function resolveKey(Request $request): string
    {
        $prefix = (string) $this->config['prefix'];
        $type   = (string) $this->config['key_type'];

        $ip   = $request->ip !== '' ? $request->ip : 'unknown';
        $uid  = $request->loginId();
        $path = $request->path;

        return match ($type) {
            'user'       => $prefix . ($uid !== null ? 'uid:' . $uid : 'ip:' . $ip),
            'route-ip'   => $prefix . 'route:' . md5($path . '|' . $ip),
            'route-user' => $prefix . 'route:' . md5($path . '|' . ($uid !== null ? $uid : $ip)),
            'all'        => $prefix . 'global',
            default      => $prefix . 'ip:' . $ip,
        };
    }

    /**
     * 获取缓存驱动（支持指定独立 store）。
     */
    private function store()
    {
        $store = $this->config['store'];

        return $store ? $this->cache->store($store) : $this->cache->store();
    }

    /**
     * 构造 429 响应。
     */
    private function buildTooManyRequestsResponse(int $limit, int $hits, int $decay): Response
    {
        /** @var array<string, string> $headers */
        $headers = ['Retry-After' => (string) $decay];

        if ($this->config['with_headers']) {
            $headers['X-RateLimit-Limit']     = (string) $limit;
            $headers['X-RateLimit-Remaining'] = '0';
            $headers['X-RateLimit-Reset']     = (string) (time() + $decay);
        }

        return new JsonResponse([
            'code' => 429,
            'msg'  => (string) $this->config['message'],
        ], 429, $headers);
    }
}
