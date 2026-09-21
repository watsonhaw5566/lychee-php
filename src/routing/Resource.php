<?php

declare(strict_types=1);

namespace Lychee\routing;

use Attribute;

/**
 * 资源路由注解，标注在控制器类上。
 *
 * 标注 #[Resource] 的控制器会自动注册以下资源动作路由
 * （方法存在且为 public 时生效，无需再为每个方法声明 #[Route]）：
 *
 *   index()         => GET    /path
 *   save()          => POST   /path
 *   read($id)       => GET    /path/{id}
 *   update($id)     => PUT    /path/{id}  (同时支持 PATCH)
 *   delete($id)     => DELETE /path/{id}
 *   batch_delete()  => DELETE /path
 *
 * 若某个方法已显式声明 #[Route]，则以显式声明为准，自动注册会跳过该方法。
 *
 * prefix 用于覆盖全局 route_prefix（config/app.php 中的 route_prefix）：
 *   null（默认）：使用全局 route_prefix
 *   ''：不使用任何前缀
 *   'custom'：使用指定前缀替代全局前缀
 *
 * cache 用于启用路由级响应缓存（仅对 GET 请求生效），作用于所有资源动作：
 *   null（默认）：不缓存
 *   正整数：缓存响应内容的秒数
 *   方法上显式 #[Route(cache: xxx)] 可覆盖类级配置
 *
 * 示例：
 *   // 假设全局 route_prefix 为 'api'
 *   #[Resource('/users')]                      // 路由 => /api/users...
 *   #[Resource('/users', prefix: '')]          // 路由 => /users...（跳过全局前缀）
 *   #[Resource('/users', prefix: 'admin')]     // 路由 => /admin/users...
 *   #[Resource('/users', cache: 300)]          // 所有资源动作缓存 5 分钟
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Resource
{
    public function __construct(
        public string $path,
        public ?string $prefix = null,
        public ?int $cache = null,
    ) {
    }
}
