<?php

declare(strict_types=1);

namespace Tests\stub\app\controller;

use Lychee\http\JsonResponse;
use Lychee\routing\Middleware;
use Lychee\routing\Route;
use Tests\stub\app\middleware\SetLoginIdMiddleware;

/**
 * 用户控制器。
 */
class IndexController
{
    #[Route('/')]
    public function index(): JsonResponse
    {
        return new JsonResponse(['message' => 'Hello, Lychee PHP!']);
    }

    #[Route('/request-context')]
    #[Middleware(SetLoginIdMiddleware::class)]
    public function requestContext(): JsonResponse
    {
        // 通过全局助手函数读取，验证容器中的实例与中间件管道一致
        return new JsonResponse([
            'loginId' => request()->loginId(),
        ]);
    }
}
