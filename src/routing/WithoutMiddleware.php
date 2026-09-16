<?php

declare(strict_types=1);

namespace Lychee\routing;

use Attribute;

/**
 * 排除中间件 Attribute，标注在方法上。
 *
 * 当控制器类标注了 #[Middleware] 时，可在特定方法上使用本注解
 * 跳过类级中间件，适用于登录、注册等无需鉴权的方法。
 *
 * 用法：
 *   #[WithoutMiddleware]                                // 跳过所有类级中间件
 *   #[WithoutMiddleware(AuthMiddleware::class)]          // 跳过指定中间件
 *   #[WithoutMiddleware([AuthMiddleware::class, ...])]   // 跳过多个指定中间件
 */
#[Attribute(Attribute::TARGET_METHOD)]
class WithoutMiddleware
{
    /**
     * @param class-string|list<class-string> $middleware 要排除的中间件类名，留空表示排除所有类级中间件
     */
    public function __construct(
        public readonly string|array $middleware = [],
    ) {
    }
}
