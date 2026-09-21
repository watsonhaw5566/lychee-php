<?php

declare(strict_types=1);

namespace Tests\stub\app\controller;

use Lychee\http\JsonResponse;
use Lychee\routing\Route;

/**
 * 用于测试路由缓存的控制器。
 */
class CacheController
{
    private static int $callCount = 0;

    /**
     * 带缓存的路由，缓存 3600 秒。
     */
    #[Route('/cached', cache: 3600)]
    public function cached(): JsonResponse
    {
        self::$callCount++;

        return new JsonResponse([
            'message' => 'cached response',
            'count'   => self::$callCount,
        ]);
    }

    /**
     * 不带缓存的路由。
     */
    #[Route('/no-cache')]
    public function noCache(): JsonResponse
    {
        self::$callCount++;

        return new JsonResponse([
            'message' => 'no cache',
            'count'   => self::$callCount,
        ]);
    }

    /**
     * 重置调用计数（测试辅助方法，不注册为路由）。
     */
    public static function resetCount(): void
    {
        self::$callCount = 0;
    }
}
