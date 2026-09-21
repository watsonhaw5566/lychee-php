<?php

declare(strict_types=1);

namespace Tests\stub\app\controller;

use Lychee\http\JsonResponse;
use Lychee\routing\Resource;
use Lychee\routing\Route;

/**
 * 用于测试资源路由缓存的控制器。
 *
 * #[Resource(cache: 300)] 使所有资源动作缓存 300 秒。
 * 方法上的 #[Route(cache: 60)] 可覆盖类级配置。
 */
#[Resource('/cached-posts', cache: 300)]
class CachedPostController
{
    private static int $indexCount = 0;
    private static int $readCount  = 0;
    private static int $saveCount  = 0;

    public function index(): JsonResponse
    {
        self::$indexCount++;

        return new JsonResponse(['action' => 'index', 'count' => self::$indexCount]);
    }

    public function read(int $id): JsonResponse
    {
        self::$readCount++;

        return new JsonResponse(['action' => 'read', 'id' => $id, 'count' => self::$readCount]);
    }

    /**
     * save 是 POST 请求，不应被缓存。
     */
    public function save(): JsonResponse
    {
        self::$saveCount++;

        return new JsonResponse(['action' => 'save', 'count' => self::$saveCount]);
    }

    /**
     * 自定义路由，覆盖类级 cache 为 60 秒。
     */
    #[Route('/cached-posts/custom', cache: 60)]
    public function custom(): JsonResponse
    {
        self::$indexCount++;

        return new JsonResponse(['action' => 'custom', 'count' => self::$indexCount]);
    }

    public static function resetCount(): void
    {
        self::$indexCount = 0;
        self::$readCount  = 0;
        self::$saveCount  = 0;
    }
}
