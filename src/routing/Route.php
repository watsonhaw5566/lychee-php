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
 *
 * prefix 用于覆盖全局 route_prefix（config/app.php 中的 route_prefix）：
 *   null（默认）：使用全局 route_prefix
 *   ''：不使用任何前缀
 *   'custom'：使用指定前缀替代全局前缀
 *
 *   // 假设全局 route_prefix 为 'api'
 *   #[Route('/users')]                      // GET /api/users
 *   #[Route('/users', prefix: '')]          // GET /users（跳过全局前缀）
 *   #[Route('/users', prefix: 'admin')]     // GET /admin/users
 *
 * cache 用于启用路由级响应缓存（仅对 GET 请求生效）：
 *   null（默认）：不缓存
 *   正整数：缓存响应内容的秒数
 *
 *   #[Route('/articles', cache: 3600)]      // 缓存 1 小时
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Route
{
    public function __construct(
        public string $path,
        public string $method = 'GET',
        public string $name = '',
        public ?string $prefix = null,
        public ?int $cache = null,
    ) {
    }
}
