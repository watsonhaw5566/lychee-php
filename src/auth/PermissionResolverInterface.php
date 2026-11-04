<?php

declare(strict_types=1);

namespace Lychee\auth;

/**
 * 权限解析器接口。
 *
 * 负责查询指定用户是否拥有某个功能权限。
 * 业务层实现此接口，从数据库 / 缓存中读取用户角色与权限列表。
 *
 * SaToken 默认绑定 NullPermissionResolver（全部放行），
 * 业务层在 ServiceProvider 中替换为实际实现即可。
 */
interface PermissionResolverInterface
{
    /**
     * 获取用户拥有的全部权限标识列表。
     *
     * @param int $loginId 当前登录用户 ID
     * @return array<int, string> 权限标识列表，如 ['user.list', 'user.edit', 'order.delete']
     */
    public function getPermissions(int $loginId): array;

    /**
     * 判断用户是否拥有指定权限。
     *
     * 默认实现可基于 getPermissions() 做 in_array 判断，
     * 业务层可覆盖以支持通配符（如 'user.*'）等高级匹配。
     *
     * @param int    $loginId    当前登录用户 ID
     * @param string $permission 权限标识
     */
    public function hasPermission(int $loginId, string $permission): bool;
}
