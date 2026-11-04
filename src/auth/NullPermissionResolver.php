<?php

declare(strict_types=1);

namespace Lychee\auth;

/**
 * 空权限解析器（默认实现）。
 *
 * 所有权限检查均返回 true，即全部放行。
 * 框架启动时绑定此实现，业务层可替换为实际的权限查询逻辑。
 */
class NullPermissionResolver implements PermissionResolverInterface
{
    public function getPermissions(int $loginId): array
    {
        return [];
    }

    public function hasPermission(int $loginId, string $permission): bool
    {
        return true;
    }
}
