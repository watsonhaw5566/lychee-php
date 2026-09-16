<?php

declare(strict_types=1);

namespace Lychee\routing;

use Attribute;

/**
 * 路由定义 Attribute。
 *
 * method 默认为 GET，可省略：
 *   #[Route('/users')]              // GET /users
 *   #[Route('/users', 'POST')]      // POST /users
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Route
{
    public function __construct(
        public string $path,
        public string $method = 'GET',
        public string $name = '',
    ) {
    }
}
