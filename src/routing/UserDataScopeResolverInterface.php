<?php

declare(strict_types=1);

namespace Lychee\routing;

/**
 * 用户数据范围解析器接口。
 *
 * 业务层实现此接口，负责：
 *   - 判断用户是否为管理员（管理员可查看所有数据）
 *   - 获取用户在指定模块下的数据权限级别
 *
 * 框架不提供默认实现——HasDataPermission 默认从容器中解析此接口，
 * 未绑定时采用最保守策略（非管理员、self 级别）。
 *
 * 项目只需实现一次并绑定到容器，所有使用 HasDataPermission 的控制器
 * 都会自动复用同一套判断逻辑，无需在每个控制器中重复覆盖 isAdmin() / getPermissionLevel()。
 */
interface UserDataScopeResolverInterface
{
    /**
     * 判断用户是否为管理员。
     *
     * 管理员在数据权限层面可查看所有数据（不过滤）。
     *
     * @param int $userId 用户 ID
     */
    public function isAdmin(int $userId): bool;

    /**
     * 获取用户在指定模块下的数据权限级别。
     *
     * @param int    $userId 用户 ID
     * @param string $module 模块标识（由控制器 getDataPermissionModule() 返回）
     * @return string 'all' | 'dept_tree' | 'dept' | 'self'
     */
    public function getPermissionLevel(int $userId, string $module): string;
}
