<?php

declare(strict_types=1);

namespace Lychee\routing;

/**
 * 部门解析器接口。
 *
 * 负责查询用户所属部门以及部门层级关系。
 * 业务层实现此接口，从数据库 / 缓存中读取部门数据。
 *
 * 框架不提供默认实现——仅当 HasDataPermission 的权限级别为
 * dept / dept_tree 时，才从容器中解析此接口。
 * 未绑定时会抛出异常，提示业务层需要提供实现。
 */
interface DeptResolverInterface
{
    /**
     * 获取用户所属部门 ID。
     *
     * @param int $userId 用户 ID
     * @return int|null 部门 ID，无部门时返回 null
     */
    public function getUserDeptId(int $userId): ?int;

    /**
     * 获取指定部门及其所有下级部门的 ID 列表（含自身）。
     *
     * 用于 dept_tree 级别的数据权限过滤。
     * 建议业务层对结果做缓存以避免递归查询开销。
     *
     * @param int $deptId 部门 ID
     * @return array<int, int> 部门 ID 列表
     */
    public function getSubDeptIds(int $deptId): array;
}
