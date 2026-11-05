<?php

declare(strict_types=1);

namespace Lychee\orm\relation;

use Lychee\orm\Collection;
use Lychee\orm\Model;
use Lychee\orm\Query;

/**
 * 多对多关联。
 *
 *   $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id', 'id', 'id');
 *
 * 参数说明：
 * - related：目标模型类名
 * - table：中间表名（逻辑名，不含前缀），默认按双方表名字母序拼接，如 role_user
 * - foreignPivotKey：中间表中指向当前模型（父）的外键字段
 * - relatedPivotKey：中间表中指向目标模型的外键字段
 * - parentKey：父模型的本地键（通常是 id）
 * - relatedKey：目标模型的本地键（通常是 id）
 *
 * @template TRelated of Model
 * @extends Relation<TRelated>
 */
class BelongsToMany extends Relation
{
    /** 中间表逻辑名（不含前缀） */
    protected string $pivotTable;

    /** 中间表中指向目标模型的外键字段 */
    protected string $relatedPivotKey;

    /** 父模型的本地键（通常是 id） */
    protected string $parentKey;

    /** 需要从中间表读取的额外字段（如 quantity、expired_at） */
    protected array $pivotColumns = [];

    /**
     * @param class-string<TRelated> $related
     */
    public function __construct(
        Model $parent,
        string $related,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
    ) {
        // 复用基类构造器：foreignKey = 中间表外键(指向父)，localKey = 目标模型本地键
        parent::__construct($parent, $related, $foreignPivotKey, $relatedKey);

        $this->pivotTable      = $table;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey       = $parentKey;
    }

    public function getParentKey(): string
    {
        return $this->parentKey;
    }

    public function getRelatedKey(): string
    {
        return $this->localKey;
    }

    /**
     * 中间表中指向父模型的外键字段。
     */
    public function getForeignPivotKey(): string
    {
        return $this->foreignKey;
    }

    /**
     * 中间表中指向目标模型的外键字段。
     */
    public function getRelatedPivotKey(): string
    {
        return $this->relatedPivotKey;
    }

    /**
     * 中间表逻辑名（不含前缀）。
     */
    public function getPivotTable(): string
    {
        return $this->pivotTable;
    }

    /**
     * 带表前缀的中间表全名。
     */
    protected function getPrefixedPivotTable(): string
    {
        $prefix = (string) $this->parent->getConnection()->getConfig('prefix', '');

        return $prefix . $this->pivotTable;
    }

    /**
     * 构造一个面向中间表的查询器（无模型绑定）。
     */
    protected function newPivotQuery(): Query
    {
        $query = new Query($this->parent->getConnection());
        $query->name($this->pivotTable);

        return $query;
    }

    /**
     * 指定需要从中间表读取的额外字段。
     *
     * 用法：
     *   $this->belongsToMany(Role::class)->withPivot('expired_at');
     *   $this->belongsToMany(Product::class)->withPivot(['quantity', 'price']);
     *
     * @param string|array<int, string> $columns
     */
    public function withPivot(string|array $columns): static
    {
        $this->pivotColumns = array_values(array_unique(array_merge(
            $this->pivotColumns,
            is_array($columns) ? $columns : [$columns],
        )));

        return $this;
    }

    public function getResults(): Collection
    {
        $parentKeyValue = $this->parent->getAttr($this->parentKey);
        if ($parentKeyValue === null) {
            return new Collection();
        }

        // 1. 查中间表拿到目标键及（可选的）pivot 字段
        $pivotRows = $this->newPivotQuery()
            ->where($this->foreignKey, $parentKeyValue)
            ->select();

        if ($pivotRows === []) {
            return new Collection();
        }

        $relatedKeys = [];
        $pivotMap    = []; // relatedKey => pivot extra data
        foreach ($pivotRows as $row) {
            $rk            = $row[$this->relatedPivotKey];
            $relatedKeys[] = $rk;
            if ($this->pivotColumns !== []) {
                $pivotMap[$rk] = $this->extractPivotData($row);
            }
        }
        $relatedKeys = array_values(array_unique($relatedKeys, SORT_REGULAR));

        // 2. 查目标模型
        /** @var Collection<array-key, Model> $results */
        $results = $this->query->whereIn($this->localKey, $relatedKeys)->select();

        // 3. 附加 pivot 字段
        if ($this->pivotColumns !== []) {
            foreach ($results as $model) {
                $rk = $model->getAttr($this->localKey);
                if (isset($pivotMap[$rk])) {
                    $model->setRelation('pivot', $pivotMap[$rk]);
                }
            }
        }

        return $results;
    }

    public function eagerLoad(array $models, string $name): void
    {
        $keys = $this->collectKeys($models, $this->parentKey);
        if ($keys === []) {
            foreach ($models as $m) {
                $m->setRelation($name, new Collection());
            }

            return;
        }

        // 1. 批量查中间表
        $pivotRows = $this->newPivotQuery()
            ->whereIn($this->foreignKey, $keys)
            ->select();

        if ($pivotRows === []) {
            foreach ($models as $m) {
                $m->setRelation($name, new Collection());
            }

            return;
        }

        // relatedKey => [parentKey, ...]
        $relatedToParents = [];
        // relatedKey => pivot extra data
        $pivotMap    = [];
        $relatedKeys = [];
        foreach ($pivotRows as $row) {
            $rk                      = $row[$this->relatedPivotKey];
            $pk                      = $row[$this->foreignKey];
            $relatedToParents[$rk][] = $pk;
            $relatedKeys[]           = $rk;
            if ($this->pivotColumns !== []) {
                $pivotMap[$rk] = $this->extractPivotData($row);
            }
        }
        $relatedKeys = array_values(array_unique($relatedKeys, SORT_REGULAR));

        // 2. 批量查目标模型
        /** @var array<int, Model> $results */
        $results = $this->query->whereIn($this->localKey, $relatedKeys)->select()->all();

        // 3. 按父键分组
        $grouped = [];
        foreach ($results as $r) {
            $rk = $r->getAttr($this->localKey);
            if ($this->pivotColumns !== [] && isset($pivotMap[$rk])) {
                $r->setRelation('pivot', $pivotMap[$rk]);
            }
            foreach ($relatedToParents[$rk] as $parentKeyVal) {
                $grouped[$parentKeyVal][] = $r;
            }
        }

        foreach ($models as $m) {
            $k = $m->getAttr($this->parentKey);
            $m->setRelation($name, new Collection($grouped[$k] ?? []));
        }
    }

    /**
     * 从中间表行中提取指定的额外字段。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function extractPivotData(array $row): array
    {
        $data = [];
        foreach ($this->pivotColumns as $col) {
            if (array_key_exists($col, $row)) {
                $data[$col] = $row[$col];
            }
        }

        return $data;
    }

    /**
     * 统计每个父模型的关联记录数（基于中间表聚合）。
     *
     * @param  array<int, Model> $models
     * @return array<mixed, int>
     */
    public function getRelationCount(array $models): array
    {
        $keys = $this->collectKeys($models, $this->parentKey);
        if ($keys === []) {
            return [];
        }

        $rows = $this->newPivotQuery()
            ->field("{$this->foreignKey} AS pivot_key, COUNT(*) AS aggregate_count")
            ->whereIn($this->foreignKey, $keys)
            ->group($this->foreignKey)
            ->select();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['pivot_key']] = (int) $row['aggregate_count'];
        }

        return $result;
    }

    // ── 中间表写入操作 ──────────────────────────────────────────────────

    /**
     * 附加关联（向中间表插入记录）。
     *
     * 用法：
     *   $user->roles()->attach($roleId);
     *   $user->roles()->attach([1, 2, 3]);
     *   $user->roles()->attach([1 => ['expired_at' => '2026-12-31'], 2 => []]);
     *
     * @param int|string|array<int|string, mixed> $id
     * @param array<string, mixed>                $attributes 附加到所有记录的公共 pivot 字段
     */
    public function attach(int|string|array $id, array $attributes = []): void
    {
        $parentKeyValue = $this->parent->getAttr($this->parentKey);
        if ($parentKeyValue === null) {
            return;
        }

        $records = $this->buildPivotRecords($id, $attributes, $parentKeyValue);
        if ($records === []) {
            return;
        }

        $this->insertPivotRecords($records);
    }

    /**
     * 解除关联（从中间表删除记录）。
     *
     * 用法：
     *   $user->roles()->detach();              // 解除当前模型的全部关联
     *   $user->roles()->detach($roleId);       // 解除指定关联
     *   $user->roles()->detach([1, 2, 3]);     // 解除多个关联
     *
     * @param int|string|array<int, int|string>|null $ids
     */
    public function detach(int|string|array|null $ids = null): int
    {
        $parentKeyValue = $this->parent->getAttr($this->parentKey);
        if ($parentKeyValue === null) {
            return 0;
        }

        $query = $this->newPivotQuery()->where($this->foreignKey, $parentKeyValue);

        if ($ids !== null && $ids !== []) {
            $idList = is_array($ids) ? $ids : [$ids];
            $query->whereIn($this->relatedPivotKey, $idList);
        }

        return $query->delete();
    }

    /**
     * 同步关联：以给定列表为准，删除多余的、插入缺失的。
     *
     * 用法：
     *   $user->roles()->sync([1, 2, 3]);
     *   $user->roles()->sync([1 => ['expired_at' => '2026-12-31'], 2 => []]);
     *
     * @param array<int|string, mixed> $ids
     * @return array{attached: array<int, mixed>, detached: array<int, mixed>}
     */
    public function sync(array $ids): array
    {
        $parentKeyValue = $this->parent->getAttr($this->parentKey);
        if ($parentKeyValue === null) {
            return ['attached' => [], 'detached' => []];
        }

        return $this->parent->getConnection()->transaction(function () use ($ids, $parentKeyValue): array {
            // 1. 查当前已关联的目标键
            $current = $this->newPivotQuery()
                ->where($this->foreignKey, $parentKeyValue)
                ->column($this->relatedPivotKey);
            $current = array_map('strval', $current);

            // 2. 解析目标列表
            $parsed  = $this->parseSyncIds($ids);
            $newKeys = array_keys($parsed);

            // 3. 计算差异
            $detach = array_diff($current, $newKeys);
            $attach = array_diff($newKeys, $current);
            $update = array_intersect($current, $newKeys);

            $detached = [];
            $attached = [];

            if ($detach !== []) {
                $this->newPivotQuery()
                    ->where($this->foreignKey, $parentKeyValue)
                    ->whereIn($this->relatedPivotKey, $detach)
                    ->delete();
                $detached = array_values($detach);
            }

            // 已存在的关联：若附带 pivot 字段则更新
            foreach ($update as $key) {
                $attrs = $parsed[$key];
                if ($attrs !== []) {
                    $this->newPivotQuery()
                        ->where($this->foreignKey, $parentKeyValue)
                        ->where($this->relatedPivotKey, $key)
                        ->update($attrs);
                }
            }

            if ($attach !== []) {
                $records = [];
                foreach ($attach as $key) {
                    $attrs     = $parsed[$key];
                    $records[] = array_merge([
                        $this->foreignKey      => $parentKeyValue,
                        $this->relatedPivotKey => $key,
                    ], $attrs);
                }
                $this->insertPivotRecords($records);
                $attached = array_values($attach);
            }

            return ['attached' => $attached, 'detached' => $detached];
        });
    }

    /**
     * 切换关联状态：已存在则删除，不存在则插入。
     *
     * 用法：
     *   $user->roles()->toggle([1, 2, 3]);
     *
     * @param array<int, int|string> $ids
     * @return array{attached: array<int, mixed>, detached: array<int, mixed>}
     */
    public function toggle(array $ids): array
    {
        $parentKeyValue = $this->parent->getAttr($this->parentKey);
        if ($parentKeyValue === null) {
            return ['attached' => [], 'detached' => []];
        }

        return $this->parent->getConnection()->transaction(function () use ($ids, $parentKeyValue): array {
            $current = $this->newPivotQuery()
                ->where($this->foreignKey, $parentKeyValue)
                ->column($this->relatedPivotKey);
            $current = array_map('strval', $current);

            $ids = array_map('strval', $ids);

            $detach = array_intersect($current, $ids);
            $attach = array_diff($ids, $current);

            $attached = [];
            $detached = [];

            if ($detach !== []) {
                $this->newPivotQuery()
                    ->where($this->foreignKey, $parentKeyValue)
                    ->whereIn($this->relatedPivotKey, $detach)
                    ->delete();
                $detached = array_values($detach);
            }

            if ($attach !== []) {
                $records = [];
                foreach ($attach as $key) {
                    $records[] = [
                        $this->foreignKey      => $parentKeyValue,
                        $this->relatedPivotKey => $key,
                    ];
                }
                $this->insertPivotRecords($records);
                $attached = array_values($attach);
            }

            return ['attached' => $attached, 'detached' => $detached];
        });
    }

    /**
     * 解析 attach/sync 的输入，统一转换为 [relatedKey => pivotAttributes] 结构。
     *
     * @param int|string|array<int|string, mixed> $id
     * @param array<string, mixed>                $commonAttributes
     * @return array<string, array<string, mixed>>
     */
    protected function parseAttachIds(int|string|array $id, array $commonAttributes = []): array
    {
        if (!is_array($id)) {
            return [(string) $id => $commonAttributes];
        }

        $result = [];
        foreach ($id as $key => $value) {
            if (is_array($value)) {
                // [1 => ['expired_at' => '...']] 形式：键为关联 ID，值为 pivot 字段
                $result[(string) $key] = array_merge($commonAttributes, $value);
            } else {
                // [1, 2, 3] 形式：值本身为关联 ID
                $result[(string) $value] = $commonAttributes;
            }
        }

        return $result;
    }

    /**
     * 解析 sync 的输入为 [relatedKey => pivotAttributes]。
     *
     * @param array<int|string, mixed> $ids
     * @return array<string, array<string, mixed>>
     */
    protected function parseSyncIds(array $ids): array
    {
        $result = [];
        foreach ($ids as $key => $value) {
            if (is_array($value)) {
                // [1 => ['expired_at' => '...']] 形式
                $result[(string) $key] = $value;
            } else {
                // [1, 2, 3] 形式
                $result[(string) $value] = [];
            }
        }

        return $result;
    }

    /**
     * 构建待插入的中间表记录数组。
     *
     * @param int|string|array<int|string, mixed> $id
     * @param array<string, mixed>                $commonAttributes
     * @param mixed                               $parentKeyValue
     * @return array<int, array<string, mixed>>
     */
    protected function buildPivotRecords(int|string|array $id, array $commonAttributes, mixed $parentKeyValue): array
    {
        $parsed = $this->parseAttachIds($id, $commonAttributes);

        $records = [];
        foreach ($parsed as $relatedKey => $attrs) {
            $records[] = array_merge([
                $this->foreignKey      => $parentKeyValue,
                $this->relatedPivotKey => $relatedKey,
            ], $attrs);
        }

        return $records;
    }

    /**
     * 批量插入中间表记录。
     *
     * @param array<int, array<string, mixed>> $records
     */
    protected function insertPivotRecords(array $records): void
    {
        if ($records === []) {
            return;
        }

        $columns = array_keys($records[0]);
        $table   = $this->getPrefixedPivotTable();

        // 构建批量 INSERT：INSERT INTO table (a,b,c) VALUES (?,?,?),(?,?,?)
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $groups       = implode(', ', array_fill(0, count($records), $placeholders));

        $wrappedColumns = array_map(fn (string $c) => $this->wrapIdentifier($c), $columns);
        $sql            = "INSERT INTO {$this->wrapIdentifier($table)} (" . implode(', ', $wrappedColumns) . ") VALUES {$groups}";

        $bindings = [];
        foreach ($records as $record) {
            foreach ($columns as $col) {
                $bindings[] = $record[$col] ?? null;
            }
        }

        $this->parent->getConnection()->execute($sql, $bindings);
    }

    /**
     * 用反引号包裹标识符（适配 SQLite/MySQL）。
     */
    protected function wrapIdentifier(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }
}
