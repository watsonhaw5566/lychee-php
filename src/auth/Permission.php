<?php

declare(strict_types=1);

namespace Lychee\auth;

use Attribute;

/**
 * 权限声明注解。
 *
 * 可标注在控制器类或方法上，声明访问所需的权限标识。
 * 多个权限之间为 OR 关系——拥有其中任意一个即可通过校验。
 *
 * 用法：
 *   // 方法级别
 *   #[Permission('order.edit')]
 *   public function update(...) { ... }
 *
 *   // 多权限（OR）
 *   #[Permission('order.edit', 'order.admin')]
 *   public function update(...) { ... }
 *
 *   // 类级别（对该控制器所有方法生效，方法级别可覆盖）
 *   #[Permission('order.view')]
 *   class OrderController { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Permission
{
    /** @var array<int, string> */
    public readonly array $permissions;

    public function __construct(string ...$permissions)
    {
        $this->permissions = $permissions;
    }
}
