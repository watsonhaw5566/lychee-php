<?php

declare(strict_types=1);

namespace Lychee\routing;

use Attribute;

/**
 * 中间件挂载 Attribute。
 *
 * #[Middleware(AuthMiddleware::class)]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Middleware
{
    /**
     * @param class-string $middleware
     */
    public function __construct(
        public string $middleware,
    ) {
    }
}
