<?php

declare(strict_types=1);

namespace Lychee\routing;

use think\db\Query;
use think\Model;

/**
 * 多租户 Trait（基于表字段 tenant_id 隔离，共享数据库 + 共享表）。
 *
 * 按需 use，不强制。仅依赖 request()->loginId()，不依赖具体用户/租户模型。
 *
 * 覆盖 ResourceController 的两个钩子：
 *   - applyTenantScope(): 查询时自动附加 where(tenant_id, 当前租户ID)
 *   - fillTenantId():    新建/更新时自动填充 tenant_id 字段
 *
 * 与 HasDataPermission 可同时使用；若两者都定义了 isAdmin()，
 * 需在控制器类中覆盖 isAdmin() 以解决冲突（类方法优先于 trait）。
 */
trait HasTenant
{
    /**
     * 多租户配置。
     *
     * - enabled:        是否开启租户隔离
     * - tenantIdField:  租户 ID 字段名
     * - autoFill:       新建/更新时是否自动填充 tenant_id
     * - bypassForAdmin: 平台管理员是否跳过租户隔离
     */
    protected array $tenantConfig = [
        'enabled'        => true,
        'tenantIdField'  => 'tenant_id',
        'autoFill'       => true,
        'bypassForAdmin' => true,
    ];

    /**
     * 获取当前租户 ID。
     *
     * 默认返回 null（不做隔离），子类必须覆盖此方法。
     * 典型实现：从当前登录用户的 tenant_id 字段读取。
     */
    protected function getTenantId(): ?int
    {
        return null;
    }

    /**
     * 应用租户隔离到查询（覆盖 ResourceController::applyTenantScope）。
     *
     * @param Model|Query $query
     * @return Model|Query
     */
    protected function applyTenantScope(Model|Query $query): Model|Query
    {
        if (!$this->tenantConfig['enabled']) {
            return $query;
        }

        if ($this->isTenantAdminBypassed()) {
            return $query;
        }

        $tenantId = $this->getTenantId();
        if ($tenantId === null) {
            return $query;
        }

        $query->where($this->tenantConfig['tenantIdField'], $tenantId);

        return $query;
    }

    /**
     * 新建/更新时自动填充 tenant_id（覆盖 ResourceController::fillTenantId）。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function fillTenantId(array $data): array
    {
        if (!$this->tenantConfig['enabled'] || !$this->tenantConfig['autoFill']) {
            return $data;
        }

        if ($this->isTenantAdminBypassed()) {
            return $data;
        }

        $tenantId = $this->getTenantId();
        if ($tenantId === null) {
            return $data;
        }

        $field = $this->tenantConfig['tenantIdField'];
        if (!isset($data[$field])) {
            $data[$field] = $tenantId;
        }

        return $data;
    }

    /**
     * 检查单条数据是否属于当前租户。
     */
    protected function isTenantData(int $dataTenantId): bool
    {
        if (!$this->tenantConfig['enabled']) {
            return true;
        }

        if ($this->isTenantAdminBypassed()) {
            return true;
        }

        $tenantId = $this->getTenantId();
        if ($tenantId === null) {
            return false;
        }

        return $dataTenantId === $tenantId;
    }

    /**
     * 判断当前请求是否以管理员身份跳过租户隔离。
     */
    protected function isTenantAdminBypassed(): bool
    {
        if (!$this->tenantConfig['bypassForAdmin']) {
            return false;
        }

        $userId = request()->loginId();
        if ($userId === null) {
            return false;
        }

        return $this->isAdmin($userId);
    }

    /**
     * 判断用户是否为平台管理员（可跳过租户隔离）。
     *
     * 默认返回 false。子类可覆盖此方法接入实际的管理员判断逻辑。
     *
     * 注意：若同时 use HasDataPermission，两者都定义了 isAdmin()，
     * 需在控制器类中覆盖 isAdmin() 以解决 trait 冲突。
     */
    protected function isAdmin(int $userId): bool
    {
        return false;
    }
}
