<?php

declare(strict_types=1);

namespace Lychee\routing;

/**
 * 路由匹配结果。
 */
class RouteMatch
{
    /**
     * @param class-string $controller
     * @param array<string, string> $params
     * @param array<class-string> $middlewares
     */
    public function __construct(
        public readonly string $controller,
        public readonly string $action,
        public readonly array $params = [],
        public readonly array $middlewares = [],
    ) {
    }
}
