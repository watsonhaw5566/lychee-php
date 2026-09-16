<?php

declare(strict_types=1);

namespace Lychee\routing;

use think\db\Query;
use think\Model;

/**
 * 数据权限 Trait：按需 use，不强制。
 *
 * 仅依赖 request()->loginId()，不依赖具体用户模型。
 * 管理员判断与权限级别读取通过可覆盖的方法实现，
 * 默认采用最保守策略（非管理员、self 级别）。
 *
 * 覆盖 ResourceController::applyDataPermission，按用户角色的数据范围过滤查询。
 */
trait HasDataPermission
{
    /**
     * 数据权限配置。
     *
     * - enabled:     是否开启数据权限控制
     * - userIdField: 数据所有者字段名
     * - module:      当前模块标识，用于从 data_scope 匹配权限级别
     */
    protected array $dataPermission = [
        'enabled'     => false,
        'userIdField' => 'user_id',
        'module'      => '',
    ];

    /**
     * 应用数据权限过滤到查询。
     *
     * @param Model|Query $query
     * @return Model|Query
     */
    protected function applyDataPermission(Model|Query $query, ?string $module = null): Model|Query
    {
        if (!$this->dataPermission['enabled']) {
            return $query;
        }

        $userId = request()->loginId();
        if ($userId === null) {
            return $query;
        }

        if ($this->isAdmin($userId)) {
            return $query;
        }

        $level = $this->getPermissionLevel($userId, $module ?: $this->dataPermission['module']);

        if ($level === 'self') {
            $field = $this->dataPermission['userIdField'];
            $query->where($field, $userId);
        }

        return $query;
    }

    /**
     * 获取当前模块的数据权限级别。
     *
     * 默认返回 'self'（最保守策略）。子类可覆盖此方法，
     * 根据用户角色的 data_scope 返回 'all' 或 'self'。
     *
     * @return string 'all' | 'self'
     */
    protected function getPermissionLevel(int $userId, string $module): string
    {
        return 'self';
    }

    /**
     * 判断用户是否为管理员。
     *
     * 默认返回 false。子类可覆盖此方法接入实际的管理员判断逻辑。
     */
    protected function isAdmin(int $userId): bool
    {
        return false;
    }

    /**
     * 检查单条数据（按 ownerId）当前用户是否可访问。
     */
    protected function canAccessData(int $ownerId, ?string $module = null): bool
    {
        if (!$this->dataPermission['enabled']) {
            return true;
        }

        $userId = request()->loginId();
        if ($userId === null) {
            return true;
        }

        if ($this->isAdmin($userId)) {
            return true;
        }

        $level = $this->getPermissionLevel($userId, $module ?: $this->dataPermission['module']);
        if ($level === 'all') {
            return true;
        }

        return $ownerId === $userId;
    }
}
