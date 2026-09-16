<?php

declare(strict_types=1);

namespace Lychee\routing;

use Attribute;

/**
 * 资源路由前缀注解，标注在控制器类上。
 *
 * 为该控制器下所有 #[Route] 方法统一添加路径前缀，
 * 避免在每个方法路由中重复书写公共路径段。
 *
 * 示例：
 *   #[Resource('users')]
 *   class UserController
 *   {
 *       #[Route('/')]              // => GET  /users
 *       #[Route('/{id}')]          // => GET  /users/{id}
 *       #[Route('/', 'POST')]      // => POST /users
 *   }
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Resource
{
    public function __construct(
        public string $path,
    ) {
    }
}
