<?php

declare(strict_types=1);

namespace Lychee\orm\concern;

use Lychee\orm\Query;

/**
 * 软删除 trait。
 *
 * 对齐 ThinkPHP SoftDelete：
 * - 启用后 Model::delete() 会写入 $deleteTime 字段，而非真正删除行
 * - 全局查询自动追加 "{deleteTime} IS NULL" 过滤
 * - withTrashed() / onlyTrashed() 可绕过或仅看软删除
 * - restore() 恢复软删除记录
 */
trait SoftDelete
{
    protected string $deleteTime = 'delete_time';

    protected mixed $defaultSoftDelete = null;

    public function getDeleteTimeField(): string
    {
        return $this->deleteTime;
    }

    public function getDefaultSoftDelete(): mixed
    {
        return $this->defaultSoftDelete;
    }

    /**
     * 判断是否已被软删除。
     */
    public function trashed(): bool
    {
        $value = $this->getAttr($this->deleteTime);

        return $value !== null && $value !== $this->defaultSoftDelete;
    }

    /**
     * 恢复软删除记录。
     */
    public function restore(): bool
    {
        if ($this->getKey() === null) {
            return false;
        }

        $value = $this->defaultSoftDelete;

        $affected = static::query()
            ->withTrashed()
            ->where($this->getPk(), $this->getKey())
            ->update([$this->deleteTime => $value]);

        if ($affected > 0) {
            $this->setAttr($this->deleteTime, $value);
        }

        return $affected > 0;
    }

    /**
     * 应用软删除的全局查询过滤。
     *
     * 由 Model::newQuery() 调用。添加的 where 子句会被标记为 softDelete，
     * 以便 withTrashed() / onlyTrashed() 可以在后续移除它们。
     */
    public function applySoftDeleteScope(Query $query): void
    {
        if ($query->getWithTrashed()) {
            return;
        }

        if ($query->getOnlyTrashed()) {
            $query->whereNotNull($this->deleteTime);
            $query->markLastWhereSoftDelete();

            return;
        }

        if ($this->defaultSoftDelete === null) {
            $query->whereNull($this->deleteTime);
        } else {
            $query->where($this->deleteTime, $this->defaultSoftDelete);
        }
        $query->markLastWhereSoftDelete();
    }

    /**
     * 执行软删除。
     *
     * 由 Model::delete() 调用。
     */
    public function runSoftDelete(): bool
    {
        if ($this->getKey() === null) {
            return false;
        }

        $value = $this->getSoftDeleteValue();

        $affected = static::query()
            ->withTrashed()
            ->where($this->getPk(), $this->getKey())
            ->update([$this->deleteTime => $value]);

        if ($affected > 0) {
            $this->setAttr($this->deleteTime, $value);
        }

        return $affected > 0;
    }

    public function getSoftDeleteValue(): mixed
    {
        // 默认写入当前时间戳；如需 datetime 格式，模型可覆盖此方法
        $format = $this->getDateFormat();

        return $format === '' ? time() : date($format, time());
    }

    protected function getDateFormat(): string
    {
        return 'Y-m-d H:i:s';
    }
}
