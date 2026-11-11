<?php

declare(strict_types=1);

namespace Lychee\routing;

use Lychee\orm\Model;
use Lychee\orm\Query;
use ReflectionClass;
use RuntimeException;

/**
 * 数据权限 Trait：按需 use，不强制。
 *
 * 仅依赖 request()->loginId()，不依赖具体用户模型。
 * 管理员判断与权限级别读取默认从容器解析 {@see UserDataScopeResolverInterface}，
 * 未绑定时采用最保守策略（非管理员、self 级别）。
 *
 * 支持四种权限级别：
 *   - all:       查看所有数据（不过滤）
 *   - dept_tree: 本部门及下级部门的数据
 *   - dept:      仅本部门的数据
 *   - self:      仅自己的数据（默认）
 *
 * 使用 dept / dept_tree 级别时，需在容器中绑定 DeptResolverInterface 实现。
 *
 * 控制器接入方式（最简）：
 *   use HasDataPermission;
 *
 * 模块标识默认从控制器类名推导（OrderController → order），
 * 推导结果不符合预期时覆盖 getDataPermissionModule() 即可。
 *
 * 覆盖 ResourceController::applyDataPermission，按用户角色的数据范围过滤查询。
 */
trait HasDataPermission
{
    /**
     * 数据权限配置。
     *
     * - enabled:     是否开启数据权限控制（use trait 后默认开启）
     * - userIdField: 数据所有者字段名
     * - deptIdField: 数据所属部门字段名（dept / dept_tree 级别使用）
     *
     * 模块标识请通过 getDataPermissionModule() 方法返回。
     */
    protected array $dataPermission = [
        'enabled'     => true,
        'userIdField' => 'user_id',
        'deptIdField' => 'dept_id',
    ];

    /** @var DeptResolverInterface|null 缓存的部门解析器实例 */
    private ?DeptResolverInterface $deptResolver = null;

    /** @var UserDataScopeResolverInterface|null 缓存的数据范围解析器实例 */
    private ?UserDataScopeResolverInterface $dataScopeResolver = null;

    /** @var string|null 缓存的模块标识（首次推导后复用） */
    private ?string $dataPermissionModule = null;

    /**
     * 获取当前控制器的数据权限模块标识。
     *
     * 用于从用户角色的 data_scope 中匹配对应模块的权限级别。
     *
     * 默认从控制器类名推导：去除 Controller 后缀后首字母小写，
     * 例如 OrderController → order、CustomerController → customer。
     * 推导结果不符合预期时（如 AdminCustomerController 需映射为 customer），
     * 子类可覆盖此方法返回指定模块名。
     */
    protected function getDataPermissionModule(): string
    {
        if ($this->dataPermissionModule !== null) {
            return $this->dataPermissionModule;
        }

        $short = (new ReflectionClass($this))->getShortName();
        $name  = preg_replace('/Controller$/', '', $short);

        return $this->dataPermissionModule = lcfirst($name);
    }

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

        $level = $this->getPermissionLevel($userId, $module ?: $this->getDataPermissionModule());

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
     * 获取数据范围解析器。
     *
     * 从容器中解析 UserDataScopeResolverInterface，未绑定时返回 null，
     * 调用方需自行处理 null 情况（采用最保守策略）。
     */
    private function getDataScopeResolver(): ?UserDataScopeResolverInterface
    {
        if ($this->dataScopeResolver !== null) {
            return $this->dataScopeResolver;
        }

        $container = app();

        if (!$container->has(UserDataScopeResolverInterface::class)) {
            return null;
        }

        return $this->dataScopeResolver = $container->get(UserDataScopeResolverInterface::class);
    }

    /**
     * 判断用户是否为管理员。
     *
     * 默认从容器解析 UserDataScopeResolverInterface 并委托判断；
     * 未绑定或解析失败时返回 false（最保守策略）。
     * 子类可覆盖此方法接入自定义逻辑。
     */
    protected function isAdmin(int $userId): bool
    {
        $resolver = $this->getDataScopeResolver();

        return $resolver?->isAdmin($userId) ?? false;
    }

    /**
     * 获取当前模块的数据权限级别。
     *
     * 默认从容器解析 UserDataScopeResolverInterface 并委托获取；
     * 未绑定或解析失败时返回 'self'（最保守策略）。
     * 子类可覆盖此方法接入自定义逻辑。
     *
     * @return string 'all' | 'dept_tree' | 'dept' | 'self'
     */
    protected function getPermissionLevel(int $userId, string $module): string
    {
        $resolver = $this->getDataScopeResolver();

        return $resolver?->getPermissionLevel($userId, $module) ?? 'self';
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

        $level = $this->getPermissionLevel($userId, $module ?: $this->getDataPermissionModule());

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
