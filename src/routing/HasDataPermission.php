<?php

declare(strict_types=1);

namespace Lychee\routing;

use Lychee\orm\Model;
use Lychee\orm\Query;
use RuntimeException;

/**
 * 数据权限 Trait：按需 use，不强制。
 *
 * 仅依赖 request()->loginId()，不依赖具体用户模型。
 * 管理员判断与权限级别读取通过可覆盖的方法实现，
 * 默认采用最保守策略（非管理员、self 级别）。
 *
 * 支持四种权限级别：
 *   - all:       查看所有数据（不过滤）
 *   - dept_tree: 本部门及下级部门的数据
 *   - dept:      仅本部门的数据
 *   - self:      仅自己的数据（默认）
 *
 * 使用 dept / dept_tree 级别时，需在容器中绑定 DeptResolverInterface 实现。
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
     * - deptIdField: 数据所属部门字段名（dept / dept_tree 级别使用）
     * - module:      当前模块标识，用于从 data_scope 匹配权限级别
     */
    protected array $dataPermission = [
        'enabled'     => false,
        'userIdField' => 'user_id',
        'deptIdField' => 'dept_id',
        'module'      => '',
    ];

    /** @var DeptResolverInterface|null 缓存的部门解析器实例 */
    private ?DeptResolverInterface $deptResolver = null;

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

        return match ($level) {
            'all'       => $query,
            'dept_tree' => $this->applyDeptTreeScope($query, $userId),
            'dept'      => $this->applyDeptScope($query, $userId),
            default     => $this->applySelfScope($query, $userId),
        };
    }

    /**
     * self 级别：只看自己的数据。
     */
    private function applySelfScope(Model|Query $query, int $userId): Model|Query
    {
        $field = $this->dataPermission['userIdField'];
        $query->where($field, $userId);

        return $query;
    }

    /**
     * dept 级别：只看本部门的数据。
     */
    private function applyDeptScope(Model|Query $query, int $userId): Model|Query
    {
        $deptId = $this->getDeptResolver()->getUserDeptId($userId);
        if ($deptId === null) {
            // 无部门归属，回退到 self
            return $this->applySelfScope($query, $userId);
        }

        $field = $this->dataPermission['deptIdField'];
        $query->where($field, $deptId);

        return $query;
    }

    /**
     * dept_tree 级别：本部门及所有下级部门的数据。
     */
    private function applyDeptTreeScope(Model|Query $query, int $userId): Model|Query
    {
        $resolver = $this->getDeptResolver();
        $deptId   = $resolver->getUserDeptId($userId);
        if ($deptId === null) {
            return $this->applySelfScope($query, $userId);
        }

        $deptIds = $resolver->getSubDeptIds($deptId);
        $field   = $this->dataPermission['deptIdField'];
        $query->whereIn($field, $deptIds);

        return $query;
    }

    /**
     * 获取部门解析器。
     *
     * 从容器中解析 DeptResolverInterface，未绑定时抛出明确异常。
     */
    private function getDeptResolver(): DeptResolverInterface
    {
        if ($this->deptResolver !== null) {
            return $this->deptResolver;
        }

        $container = app();

        if (!$container->has(DeptResolverInterface::class)) {
            throw new RuntimeException(
                '使用 dept / dept_tree 数据权限级别时，需在容器中绑定 '
                . DeptResolverInterface::class . ' 实现'
            );
        }

        return $this->deptResolver = $container->get(DeptResolverInterface::class);
    }

    /**
     * 获取当前模块的数据权限级别。
     *
     * 默认返回 'self'（最保守策略）。子类可覆盖此方法，
     * 根据用户角色的 data_scope 返回对应级别。
     *
     * @return string 'all' | 'dept_tree' | 'dept' | 'self'
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
     * 检查单条数据当前用户是否可访问。
     *
     * @param int      $ownerId 数据所有者 ID
     * @param int|null $deptId  数据所属部门 ID（dept / dept_tree 级别时需要）
     */
    protected function canAccessData(int $ownerId, ?int $deptId = null, ?string $module = null): bool
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

        return match ($level) {
            'all'       => true,
            'dept_tree' => $this->canAccessByDeptTree($userId, $deptId),
            'dept'      => $this->canAccessByDept($userId, $deptId),
            default     => $ownerId === $userId,
        };
    }

    private function canAccessByDept(int $userId, ?int $deptId): bool
    {
        if ($deptId === null) {
            return false;
        }

        $myDeptId = $this->getDeptResolver()->getUserDeptId($userId);

        return $myDeptId !== null && $myDeptId === $deptId;
    }

    private function canAccessByDeptTree(int $userId, ?int $deptId): bool
    {
        if ($deptId === null) {
            return false;
        }

        $resolver = $this->getDeptResolver();
        $myDeptId = $resolver->getUserDeptId($userId);
        if ($myDeptId === null) {
            return false;
        }

        $subDeptIds = $resolver->getSubDeptIds($myDeptId);

        return in_array($deptId, $subDeptIds, true);
    }
}
