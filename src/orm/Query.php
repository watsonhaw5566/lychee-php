<?php

declare(strict_types=1);

namespace Lychee\orm;

use Closure;
use Lychee\orm\exception\DataNotFoundException;
use Lychee\orm\exception\DbException;
use Lychee\orm\exception\ModelNotFoundException;

/**
 * 查询构造器。
 *
 * 对齐 ThinkPHP Query 的常用链式 API：
 * where / whereIn / whereNull / whereNotNull / whereLike / whereBetween /
 * whereBetweenTime / whereRaw / whereOr（含闭包嵌套）；
 * field / order / limit / page / group / having / join；
 * count / sum / avg / min / max；
 * find / findOrFail / select / value / column / paginate；
 * insert / insertGetId / update / delete / save。
 *
 * 配合 {@see Model} 时会自动桥接 Attribute、Relation、SoftDelete 的扩展行为；
 * 独立使用（通过 facade\Db）时返回数组或原始标量。
 *
 * @template TModel of Model|null
 */
class Query
{
    protected Connection $connection;

    protected string $table = '';

    protected string $alias = '';

    protected string $pk = 'id';

    protected ?Model $model = null;

    /** @var string[] 查询字段列表；空数组表示 *（会在 build 时被 Model 的字段白名单替换） */
    protected array $fields = [];

    /** @var array<int, array{type:string, boolean:string, field:string|Closure|Raw, op?:string, value?:mixed}> */
    protected array $wheres = [];

    /** @var array<string, string> order by 字段=>方向 */
    protected array $orders = [];

    /** @var array<int, Raw> order by 原生片段 */
    protected array $orderRaw = [];

    protected ?int $limit = null;

    protected ?int $offset = null;

    protected string $group = '';

    protected string $having = '';

    /** @var array<int, mixed> having 绑定 */
    protected array $havingBindings = [];

    /** @var array<int, array{table:string, condition:string, type:string}> */
    protected array $joins = [];

    protected bool $distinct = false;

    /** @var array<int, mixed> 执行时累积的参数绑定 */
    protected array $bindings = [];

    /** @var array<int, string> 关联预加载列表 */
    protected array $with = [];

    /** @var array<int, string> 追加虚拟字段 */
    protected array $append = [];

    /** @var array<int, string> 隐藏字段 */
    protected array $hidden = [];

    /** @var array<int, string> 可见字段白名单（优先于 hidden） */
    protected array $visible = [];

    /** 是否关闭当前查询的全局软删除过滤 */
    protected bool $withTrashed = false;

    /** 仅查询已软删除数据 */
    protected bool $onlyTrashed = false;

    protected bool $failOnEmpty = false;

    /** 悲观锁子句（FOR UPDATE / LOCK IN SHARE MODE / 原生字符串），空串表示不加锁 */
    protected string $lock = '';

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function setModel(Model $model): static
    {
        $this->model = $model;
        $this->table = $model->getTable();
        $this->pk    = $model->getPk();

        return $this;
    }

    public function getModel(): ?Model
    {
        return $this->model;
    }

    public function newInstance(): static
    {
        $clone = new static($this->connection);
        if ($this->model !== null) {
            $clone->setModel($this->model);
        }
        if ($this->table !== '' && $this->model === null) {
            $clone->table = $this->table;
        }

        return $clone;
    }

    public function table(string $table): static
    {
        $this->table = $table;

        return $this;
    }

    public function name(string $name): static
    {
        $prefix      = (string) $this->connection->getConfig('prefix', '');
        $this->table = $prefix . $name;

        return $this;
    }

    public function alias(string $alias): static
    {
        $this->alias = $alias;

        return $this;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * @return array<int, string>
     */
    public function getTableFields(?string $table = null): array
    {
        return $this->connection->getTableFields($table ?? $this->table);
    }

    // ── Field ───────────────────────────────────────────────────────

    /**
     * @param string|array<int, string> $fields
     */
    public function field(string|array $fields): static
    {
        $this->fields = is_array($fields) ? $fields : array_map('trim', explode(',', $fields));

        return $this;
    }

    public function distinct(bool $enable = true): static
    {
        $this->distinct = $enable;

        return $this;
    }

    // ── Where ───────────────────────────────────────────────────────

    /**
     * 通用 WHERE。
     *
     * 支持多种签名：
     *   where('id', 1)
     *   where('id', '>', 1)
     *   where(['id' => 1, 'name' => 'foo'])
     *   where(fn ($q) => $q->where(...)->whereOr(...))
     *   where(new Raw('score > ?', [100]))
     */
    public function where(string|array|Closure|Raw $field, mixed $op = null, mixed $value = null): static
    {
        return $this->addWhere('AND', $field, $op, $value, func_num_args());
    }

    public function whereOr(string|array|Closure|Raw $field, mixed $op = null, mixed $value = null): static
    {
        return $this->addWhere('OR', $field, $op, $value, func_num_args());
    }

    protected function addWhere(string $boolean, string|array|Closure|Raw $field, mixed $op, mixed $value, int $argCount): static
    {
        if ($field instanceof Closure) {
            $this->wheres[] = [
                'type'    => 'nested',
                'boolean' => $boolean,
                'field'   => $field,
            ];

            return $this;
        }

        if ($field instanceof Raw) {
            $this->wheres[] = [
                'type'    => 'raw',
                'boolean' => $boolean,
                'field'   => $field,
            ];

            return $this;
        }

        if (is_array($field)) {
            foreach ($field as $k => $v) {
                if (is_int($k) && is_array($v) && count($v) >= 2) {
                    $this->addWhere($boolean, (string) $v[0], $v[1], $v[2] ?? null, count($v));
                } else {
                    $this->addWhere($boolean, (string) $k, '=', $v, 2);
                }
            }

            return $this;
        }

        if ($argCount === 2) {
            $value = $op;
            $op    = '=';
        }

        $op = strtoupper((string) $op);

        $this->wheres[] = [
            'type'    => 'basic',
            'boolean' => $boolean,
            'field'   => $field,
            'op'      => $op,
            'value'   => $value,
        ];

        return $this;
    }

    /**
     * @param array<int, mixed> $values
     */
    public function whereIn(string $field, array $values): static
    {
        $this->wheres[] = [
            'type'    => 'in',
            'boolean' => 'AND',
            'field'   => $field,
            'value'   => array_values($values),
            'not'     => false,
        ];

        return $this;
    }

    /**
     * @param array<int, mixed> $values
     */
    public function whereNotIn(string $field, array $values): static
    {
        $this->wheres[] = [
            'type'    => 'in',
            'boolean' => 'AND',
            'field'   => $field,
            'value'   => array_values($values),
            'not'     => true,
        ];

        return $this;
    }

    public function whereNull(string $field): static
    {
        $this->wheres[] = ['type' => 'null', 'boolean' => 'AND', 'field' => $field, 'not' => false];

        return $this;
    }

    public function whereNotNull(string $field): static
    {
        $this->wheres[] = ['type' => 'null', 'boolean' => 'AND', 'field' => $field, 'not' => true];

        return $this;
    }

    public function whereLike(string $field, string $value): static
    {
        return $this->where($field, 'LIKE', $value);
    }

    public function whereNotLike(string $field, string $value): static
    {
        return $this->where($field, 'NOT LIKE', $value);
    }

    public function whereBetween(string $field, mixed $min, mixed $max = null): static
    {
        if ($max === null && is_array($min) && count($min) === 2) {
            [$min, $max] = $min;
        }

        $this->wheres[] = [
            'type'    => 'between',
            'boolean' => 'AND',
            'field'   => $field,
            'value'   => [$min, $max],
            'not'     => false,
        ];

        return $this;
    }

    public function whereNotBetween(string $field, mixed $min, mixed $max = null): static
    {
        if ($max === null && is_array($min) && count($min) === 2) {
            [$min, $max] = $min;
        }

        $this->wheres[] = [
            'type'    => 'between',
            'boolean' => 'AND',
            'field'   => $field,
            'value'   => [$min, $max],
            'not'     => true,
        ];

        return $this;
    }

    /**
     * 时间区间（含端点）。
     *
     * $start / $end 可为 Y-m-d 或 Y-m-d H:i:s；仅传日期时自动补齐到当天 00:00:00 与 23:59:59。
     */
    public function whereBetweenTime(string $field, string $start, string $end): static
    {
        $start = strlen($start) === 10 ? $start . ' 00:00:00' : $start;
        $end   = strlen($end)   === 10 ? $end . ' 23:59:59' : $end;

        return $this->whereBetween($field, $start, $end);
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = []): static
    {
        $this->wheres[] = [
            'type'    => 'raw',
            'boolean' => 'AND',
            'field'   => new Raw($sql, $bindings),
        ];

        return $this;
    }

    public function whereExists(Closure $callback, bool $not = false): static
    {
        $this->wheres[] = [
            'type'    => 'exists',
            'boolean' => 'AND',
            'field'   => $callback,
            'not'     => $not,
        ];

        return $this;
    }

    // ── Order / Limit / Group ────────────────────────────────────────

    /**
     * 排序。
     *
     * @param string|array<string, string>|array<int, string>|Raw $field 字段名、字段=>方向映射或 Raw
     */
    public function order(string|array|Raw $field, string $direction = 'ASC'): static
    {
        if ($field instanceof Raw) {
            $this->orderRaw[] = $field;

            return $this;
        }

        if (is_array($field)) {
            foreach ($field as $k => $v) {
                if (is_int($k)) {
                    $this->orders[(string) $v] = 'ASC';
                } else {
                    $this->orders[(string) $k] = strtoupper((string) $v);
                }
            }

            return $this;
        }

        $this->orders[$field] = strtoupper($direction);

        return $this;
    }

    public function limit(int $limit, ?int $offset = null): static
    {
        $this->limit = $limit;
        if ($offset !== null) {
            $this->offset = $offset;
        }

        return $this;
    }

    public function page(int $page, int $pageSize): static
    {
        $this->limit  = $pageSize;
        $this->offset = max(0, ($page - 1) * $pageSize);

        return $this;
    }

    public function group(string $group): static
    {
        $this->group = $group;

        return $this;
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function having(string $having, array $bindings = []): static
    {
        $this->having         = $having;
        $this->havingBindings = $bindings;

        return $this;
    }

    public function join(string $table, string $condition, string $type = 'INNER'): static
    {
        $this->joins[] = ['table' => $table, 'condition' => $condition, 'type' => strtoupper($type)];

        return $this;
    }

    public function leftJoin(string $table, string $condition): static
    {
        return $this->join($table, $condition, 'LEFT');
    }

    public function rightJoin(string $table, string $condition): static
    {
        return $this->join($table, $condition, 'RIGHT');
    }

    // ── Model-friendly ──────────────────────────────────────────────

    /**
     * @param string|array<int, string> $relations
     */
    public function with(string|array $relations): static
    {
        $relations  = is_array($relations) ? $relations : [$relations];
        $this->with = array_values(array_unique(array_merge($this->with, $relations)));

        return $this;
    }

    /**
     * @param string|array<int, string> $attrs
     */
    public function append(string|array $attrs): static
    {
        $attrs        = is_array($attrs) ? $attrs : [$attrs];
        $this->append = array_values(array_unique(array_merge($this->append, $attrs)));

        return $this;
    }

    /**
     * @param array<int, string> $fields
     */
    public function hidden(array $fields): static
    {
        $this->hidden = array_values(array_unique(array_merge($this->hidden, $fields)));

        return $this;
    }

    /**
     * @param array<int, string> $fields
     */
    public function visible(array $fields): static
    {
        $this->visible = array_values(array_unique(array_merge($this->visible, $fields)));

        return $this;
    }

    public function withTrashed(): static
    {
        $this->withTrashed = true;

        return $this;
    }

    public function onlyTrashed(): static
    {
        $this->onlyTrashed = true;

        return $this;
    }

    public function failException(bool $fail = true): static
    {
        $this->failOnEmpty = $fail;

        return $this;
    }

    /**
     * 悲观锁：true=FOR UPDATE，'shared'=LOCK IN SHARE MODE，或传入自定义片段。
     */
    public function lock(bool|string $lock = true): static
    {
        if ($lock === true) {
            $this->lock = 'FOR UPDATE';
        } elseif ($lock === false) {
            $this->lock = '';
        } elseif ($lock === 'shared') {
            $this->lock = 'LOCK IN SHARE MODE';
        } else {
            $this->lock = $lock;
        }

        return $this;
    }

    // ── Fetchers ────────────────────────────────────────────────────

    /**
     * 查询单条。
     *
     * $id 不为 null 时按主键查询。
     *
     * @return Model|array<string, mixed>|null
     */
    public function find(mixed $id = null): mixed
    {
        if ($id !== null) {
            $this->where($this->pk, $id);
        }

        $this->limit(1);
        [$sql, $bindings] = $this->toSelectSql();
        $rows             = $this->connection->select($sql, $bindings);

        if ($rows === []) {
            if ($this->failOnEmpty) {
                throw new ModelNotFoundException($this->model ? $this->model::class : $this->table);
            }

            return null;
        }

        $row = $rows[0];

        if ($this->model !== null) {
            $model = $this->model::createFromDatabase($row);
            $this->applyModelPresentation($model);
            if ($this->with !== []) {
                $this->model::eagerLoadRelations([$model], $this->with);
            }

            return $model;
        }

        return $row;
    }

    public function findOrFail(mixed $id = null): Model|array
    {
        $result = $this->failException()->find($id);

        if ($result === null) {
            throw new ModelNotFoundException($this->model ? $this->model::class : $this->table);
        }

        return $result;
    }

    /**
     * 查询多条。
     *
     * @return Collection<array-key, Model>|array<int, array<string, mixed>>
     */
    public function select(): Collection|array
    {
        [$sql, $bindings] = $this->toSelectSql();
        $rows             = $this->connection->select($sql, $bindings);

        if ($this->model !== null) {
            $items = [];
            foreach ($rows as $row) {
                $model = $this->model::createFromDatabase($row);
                $this->applyModelPresentation($model);
                $items[] = $model;
            }

            if ($this->with !== []) {
                $this->model::eagerLoadRelations($items, $this->with);
            }

            if ($items === [] && $this->failOnEmpty) {
                throw new DataNotFoundException('data not found: ' . $this->model::class);
            }

            return new Collection($items);
        }

        if ($rows === [] && $this->failOnEmpty) {
            throw new DataNotFoundException('data not found: ' . $this->table);
        }

        return $rows;
    }

    protected function applyModelPresentation(Model $model): void
    {
        if ($this->hidden !== []) {
            $model->hidden($this->hidden);
        }
        if ($this->visible !== []) {
            $model->visible($this->visible);
        }
        if ($this->append !== []) {
            $model->append($this->append);
        }
    }

    /**
     * 取出一列的某个字段值。
     */
    public function value(string $field): mixed
    {
        $this->fields = [$field];
        $this->limit(1);
        [$sql, $bindings] = $this->toSelectSql();
        $rows             = $this->connection->select($sql, $bindings);

        if ($rows === []) {
            return null;
        }

        return array_values($rows[0])[0] ?? null;
    }

    /**
     * 取出一列。
     *
     * @return array<int|string, mixed>
     */
    public function column(string $field, ?string $key = null): array
    {
        $this->fields     = $key !== null ? [$field, $key] : [$field];
        [$sql, $bindings] = $this->toSelectSql();
        $rows             = $this->connection->select($sql, $bindings);

        $result = [];
        foreach ($rows as $row) {
            $value = $row[$field] ?? array_values($row)[0];
            if ($key !== null && array_key_exists($key, $row)) {
                $result[$row[$key]] = $value;
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }

    // ── Aggregates ──────────────────────────────────────────────────

    public function count(string $field = '*'): int
    {
        return (int) $this->aggregate('COUNT', $field);
    }

    public function sum(string $field): float|int
    {
        $v = $this->aggregate('SUM', $field);

        return $v === null ? 0 : (str_contains((string) $v, '.') ? (float) $v : (int) $v);
    }

    public function avg(string $field): float
    {
        return (float) $this->aggregate('AVG', $field);
    }

    public function min(string $field): mixed
    {
        return $this->aggregate('MIN', $field);
    }

    public function max(string $field): mixed
    {
        return $this->aggregate('MAX', $field);
    }

    protected function aggregate(string $func, string $field): mixed
    {
        $backup       = $this->fields;
        $this->fields = ["{$func}({$field}) AS aggregate_value"];

        [$sql, $bindings] = $this->toSelectSql(aggregate: true);
        $rows             = $this->connection->select($sql, $bindings);

        $this->fields = $backup;

        return $rows[0]['aggregate_value'] ?? null;
    }

    // ── Writes ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        [$sql, $bindings] = $this->toInsertSql($data);

        return $this->connection->execute($sql, $bindings);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insertGetId(array $data): int|string
    {
        [$sql, $bindings] = $this->toInsertSql($data);

        return $this->connection->insertGetId($sql, $bindings);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function insertAll(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $firstRow = $rows[0];
        $columns  = array_keys($firstRow);
        $values   = [];
        $bindings = [];
        foreach ($rows as $row) {
            $placeholders = [];
            foreach ($columns as $column) {
                $placeholders[] = '?';
                $bindings[]     = $row[$column] ?? null;
            }
            $values[] = '(' . implode(', ', $placeholders) . ')';
        }

        $cols = implode(', ', array_map(fn ($c) => $this->wrap($c), $columns));
        $sql  = 'INSERT INTO ' . $this->wrap($this->table) . " ({$cols}) VALUES " . implode(', ', $values);

        return $this->connection->execute($sql, $bindings);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(array $data): int
    {
        if ($data === []) {
            return 0;
        }

        $bindings = [];
        $sets     = [];
        foreach ($data as $column => $value) {
            if ($value instanceof Raw) {
                $sets[]   = $this->wrap($column) . ' = ' . $value->getValue();
                $bindings = array_merge($bindings, $value->getBindings());
            } else {
                $sets[]     = $this->wrap($column) . ' = ?';
                $bindings[] = $value;
            }
        }

        $sql = 'UPDATE ' . $this->tableClause() . ' SET ' . implode(', ', $sets);
        $sql .= $this->compileWheres($bindings);

        return $this->connection->execute($sql, $bindings);
    }

    public function delete(): int
    {
        $bindings = [];
        $sql      = 'DELETE FROM ' . $this->wrap($this->table) . $this->compileWheres($bindings);

        return $this->connection->execute($sql, $bindings);
    }

    public function inc(string $field, int|float $step = 1): int
    {
        return $this->update([$field => new Raw($this->wrap($field) . ' + ' . (0 + $step))]);
    }

    public function dec(string $field, int|float $step = 1): int
    {
        return $this->update([$field => new Raw($this->wrap($field) . ' - ' . (0 + $step))]);
    }

    // ── SQL compilation ─────────────────────────────────────────────

    /**
     * @return array{0:string, 1:array<int, mixed>}
     */
    public function toSelectSql(bool $aggregate = false): array
    {
        $bindings = [];
        $sql      = 'SELECT ';
        if ($this->distinct) {
            $sql .= 'DISTINCT ';
        }

        $sql .= $this->compileColumns($aggregate);
        $sql .= ' FROM ' . $this->tableClause();

        foreach ($this->joins as $join) {
            $sql .= ' ' . $join['type'] . ' JOIN ' . $this->wrapTable($join['table']) . ' ON ' . $join['condition'];
        }

        $sql .= $this->compileWheres($bindings);

        if ($this->group !== '') {
            $sql .= ' GROUP BY ' . $this->group;
        }
        if ($this->having !== '') {
            $sql .= ' HAVING ' . $this->having;
            foreach ($this->havingBindings as $b) {
                $bindings[] = $b;
            }
        }

        $sql .= $this->compileOrders();

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
            if ($this->offset !== null && $this->offset > 0) {
                $sql .= ' OFFSET ' . $this->offset;
            }
        }

        if ($this->lock !== '') {
            $sql .= ' ' . $this->lock;
        }

        return [$sql, $bindings];
    }

    protected function compileColumns(bool $aggregate): string
    {
        if ($this->fields !== []) {
            return implode(', ', array_map(fn ($c) => $this->wrapColumnLoose($c), $this->fields));
        }

        if ($aggregate) {
            return '*';
        }

        // 对 Model 查询：使用其字段白名单（扣除 $disuse）
        if ($this->model !== null) {
            $allowed = $this->model->getActiveFields();
            if ($allowed !== []) {
                return implode(', ', array_map(fn ($c) => $this->wrap($c), $allowed));
            }
        }

        return '*';
    }

    protected function tableClause(): string
    {
        $table = $this->wrap($this->table);
        if ($this->alias !== '') {
            $table .= ' AS ' . $this->wrap($this->alias);
        }

        return $table;
    }

    /**
     * @param array<int, mixed> &$bindings
     */
    protected function compileWheres(array &$bindings): string
    {
        if ($this->wheres === []) {
            return '';
        }

        $parts = [];
        foreach ($this->wheres as $index => $where) {
            $prefix  = $index === 0 ? '' : ' ' . $where['boolean'] . ' ';
            $parts[] = $prefix . $this->compileWhere($where, $bindings);
        }

        return ' WHERE ' . implode('', $parts);
    }

    /**
     * @param array<string, mixed> $where
     * @param array<int, mixed>    &$bindings
     */
    protected function compileWhere(array $where, array &$bindings): string
    {
        switch ($where['type']) {
            case 'basic':
                $field = $this->wrap((string) $where['field']);
                $value = $where['value'];
                if ($value instanceof Raw) {
                    foreach ($value->getBindings() as $b) {
                        $bindings[] = $b;
                    }

                    return "{$field} {$where['op']} " . $value->getValue();
                }
                $bindings[] = $value;

                return "{$field} {$where['op']} ?";

            case 'in':
                $values = (array) $where['value'];
                if ($values === []) {
                    // IN () 永远不成立；NOT IN () 永远成立
                    return $where['not'] ? '1=1' : '0=1';
                }
                $placeholders = implode(', ', array_fill(0, count($values), '?'));
                foreach ($values as $v) {
                    $bindings[] = $v;
                }
                $op = $where['not'] ? 'NOT IN' : 'IN';

                return $this->wrap((string) $where['field']) . " {$op} ({$placeholders})";

            case 'null':
                $op = $where['not'] ? 'IS NOT NULL' : 'IS NULL';

                return $this->wrap((string) $where['field']) . ' ' . $op;

            case 'between':
                $op          = $where['not'] ? 'NOT BETWEEN' : 'BETWEEN';
                [$min, $max] = $where['value'];
                $bindings[]  = $min;
                $bindings[]  = $max;

                return $this->wrap((string) $where['field']) . " {$op} ? AND ?";

            case 'raw':
                /** @var Raw $raw */
                $raw = $where['field'];
                foreach ($raw->getBindings() as $b) {
                    $bindings[] = $b;
                }

                return '(' . $raw->getValue() . ')';

            case 'nested':
                $nested = $this->newInstance();
                /** @var Closure $callback */
                $callback = $where['field'];
                $callback($nested);

                $nestedBindings = [];
                $nestedSql      = $nested->compileWheres($nestedBindings);
                if ($nestedSql === '') {
                    return '1=1';
                }
                foreach ($nestedBindings as $b) {
                    $bindings[] = $b;
                }

                // compileWheres 返回带前缀 ' WHERE '
                return '(' . substr($nestedSql, 7) . ')';

            case 'exists':
                $nested = $this->newInstance();
                /** @var Closure $callback */
                $callback = $where['field'];
                $callback($nested);
                [$subSql, $subBindings] = $nested->toSelectSql();
                foreach ($subBindings as $b) {
                    $bindings[] = $b;
                }
                $op = $where['not'] ? 'NOT EXISTS' : 'EXISTS';

                return "{$op} ({$subSql})";
        }

        throw new DbException('unknown where type: ' . $where['type']);
    }

    protected function compileOrders(): string
    {
        if ($this->orders === [] && $this->orderRaw === []) {
            return '';
        }

        $parts = [];
        foreach ($this->orders as $field => $direction) {
            $parts[] = $this->wrap($field) . ' ' . ($direction === 'DESC' ? 'DESC' : 'ASC');
        }
        foreach ($this->orderRaw as $raw) {
            $parts[] = $raw->getValue();
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0:string, 1:array<int, mixed>}
     */
    protected function toInsertSql(array $data): array
    {
        $columns  = [];
        $values   = [];
        $bindings = [];
        foreach ($data as $column => $value) {
            $columns[] = $this->wrap($column);
            if ($value instanceof Raw) {
                $values[] = $value->getValue();
                foreach ($value->getBindings() as $b) {
                    $bindings[] = $b;
                }
            } else {
                $values[]   = '?';
                $bindings[] = $value;
            }
        }

        $sql = 'INSERT INTO ' . $this->wrap($this->table)
            . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';

        return [$sql, $bindings];
    }

    protected function wrap(string $value): string
    {
        $value = trim($value);
        if ($value === '*') {
            return '*';
        }
        // 已含点号（库.表 或 表.列）：拆分分别包裹
        if (str_contains($value, '.')) {
            return implode('.', array_map(fn ($p) => $this->wrapSegment($p), explode('.', $value)));
        }

        return $this->wrapSegment($value);
    }

    protected function wrapSegment(string $value): string
    {
        if ($value === '*' || $value === '') {
            return $value;
        }
        if (str_starts_with($value, '`') && str_ends_with($value, '`')) {
            return $value;
        }

        return '`' . str_replace('`', '``', $value) . '`';
    }

    /**
     * 对字段名宽松包裹：遇到含空格、括号或 AS 的表达式直接保留。
     */
    protected function wrapColumnLoose(string $value): string
    {
        $value = trim($value);
        if ($value === '*' || $value === '') {
            return $value === '' ? '*' : $value;
        }
        if (preg_match('/[\s()*,]/', $value)) {
            return $value;
        }

        return $this->wrap($value);
    }

    protected function wrapTable(string $value): string
    {
        $value = trim($value);
        if (preg_match('/\s+AS\s+/i', $value)) {
            [$name, $alias] = preg_split('/\s+AS\s+/i', $value, 2);

            return $this->wrap(trim((string) $name)) . ' AS ' . $this->wrap(trim((string) $alias));
        }

        return $this->wrap($value);
    }

    public function getWithTrashed(): bool
    {
        return $this->withTrashed;
    }

    public function getOnlyTrashed(): bool
    {
        return $this->onlyTrashed;
    }
}
