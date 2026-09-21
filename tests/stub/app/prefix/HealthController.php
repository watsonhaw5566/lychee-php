<?php

declare(strict_types=1);

namespace Tests\stub\app\prefix;

use Lychee\http\JsonResponse;
use Lychee\routing\Route;

/**
 * 方法级 #[Route] prefix 覆盖测试。
 */
class HealthController
{
    // 使用默认全局前缀
    #[Route('/ping')]
    public function ping(): JsonResponse
    {
        return new JsonResponse(['message' => 'pong']);
    }

    // 跳过全局前缀
    #[Route('/health', prefix: '')]
    public function health(): JsonResponse
    {
        return new JsonResponse(['message' => 'ok']);
    }

    // 自定义前缀替代全局前缀
    #[Route('/dashboard', prefix: 'admin')]
    public function dashboard(): JsonResponse
    {
        return new JsonResponse(['message' => 'admin']);
    }
}
