<?php

declare(strict_types=1);

namespace Tests\stub\app\prefix;

use Lychee\http\JsonResponse;
use Lychee\routing\Resource;
use Lychee\routing\Route;

/**
 * 资源路由 + prefix 覆盖测试。
 *
 * #[Resource] 的 prefix: '' 使所有资源路由跳过全局前缀。
 */
#[Resource('orders', prefix: '')]
class OrderController
{
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => []]);
    }

    public function read(int $id): JsonResponse
    {
        return new JsonResponse(['id' => $id]);
    }
}
