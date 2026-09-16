<?php

declare(strict_types=1);

namespace Lychee\routing;

use Attribute;

/**
 * 中间件挂载 Attribute。
 *
 * 支持单个中间件、数组形式，以及重复注解（IS_REPEATABLE）：
 *
 *   #[Middleware(AuthMiddleware::class)]
 *   #[Middleware([AuthMiddleware::class, LogMiddleware::class])]
 *   #[Middleware(AuthMiddleware::class)]
 *   #[Middleware(LogMiddleware::class)]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Middleware
{
    /**
     * @param class-string|list<class-string> $middleware
     */
    public function __construct(
        public string|array $middleware,
    ) {
    }
}
