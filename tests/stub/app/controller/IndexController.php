<?php

declare(strict_types=1);

namespace Tests\stub\app\controller;

use Lychee\http\JsonResponse;
use Lychee\routing\Route;

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
}
