<?php

declare(strict_types=1);

namespace Lychee\orm;

use ArrayAccess;
use Closure;
use JsonSerializable;
use Lychee\orm\exception\DbException;
use Lychee\orm\relation\BelongsTo;
use Lychee\orm\relation\HasMany;
use Lychee\orm\relation\HasOne;
use Lychee\orm\relation\Relation;

/**
 * ORM 模型基类（Active Record）。
 *
 * 用法与 ThinkPHP Model 对齐：
 *
 *   class User extends Model {
 *       protected string $table = 'user';
 *       protected array $type   = ['balance' => 'float'];
 *       protected array $json   = ['extra'];
 *       protected array $disuse = ['legacy_col'];
 *
 *       public function orders(): HasMany {
 *           return $this->hasMany(Order::class, 'user_id', 'id');
 *       }
 *   }
 *
 *   // 静态查询代理（通过 __callStatic 转发至新 Query）
 *   User::where('id', 1)->find();
 *   User::whereIn('id', [1, 2, 3])->order(['id' => 'desc'])->select();
 *
 *   // 实例保存
 *   $user = new User();
 *   $user->name = 'foo';
 *   $user->save();
 *
 * @method static Query where(string|array|Closure|Raw $field, mixed $op = null, mixed $value = null)
 * @method static Query whereOr(string|array|Closure|Raw $field, mixed $op = null, mixed $value = null)
 * @method static Query whereIn(string $field, array $values)
 * @method static Query whereNotIn(string $field, array $values)
 * @method static Query whereNull(string $field)
 * @method static Query whereNotNull(string $field)
 * @method static Query whereLike(string $field, string $value)
 * @method static Query whereNotLike(string $field, string $value)
 * @method static Query whereBetween(string $field, mixed $min, mixed $max = null)
 * @method static Query whereNotBetween(string $field, mixed $min, mixed $max = null)
 * @method static Query whereBetweenTime(string $field, string $start, string $end)
 * @method static Query whereRaw(string $sql, array $bindings = [])
 * @method static Query whereExists(Closure $callback, bool $not = false)
 * @method static Query field(string|array $fields)
 * @method static Query order(string|array|Raw $field, string $direction = 'ASC')
 * @method static Query limit(int $limit, ?int $offset = null)
 * @method static Query page(int $page, int $pageSize)
 * @method static Query group(string $group)
 * @method static Query having(string $having, array $bindings = [])
 * @method static Query join(string $table, string $condition, string $type = 'INNER')
 * @method static Query leftJoin(string $table, string $condition)
 * @method static Query rightJoin(string $table, string $condition)
 * @method static Query with(string|array $relations)
 * @method static Query append(string|array $attrs)
 * @method static Query hidden(array $fields)
 * @method static Query visible(array $fields)
 * @method static Query withTrashed()
 * @method static Query onlyTrashed()
 * @method static Query failException(bool $fail = true)
 * @method static Query alias(string $alias)
 * @method static Query distinct(bool $enable = true)
 * @method static Query lock(bool|string $lock = true)
 * @method static int count(string $field = '*')
 * @method static int|float sum(string $field)
 * @method static float avg(string $field)
 * @method static mixed min(string $field)
 * @method static mixed max(string $field)
 *
 * @phpstan-consistent-constructor
 */
abstract class Model implements ArrayAccess, JsonSerializable
{
    // ── 全局解析器与钩子 ────────────────────────────────────────────────

    protected static ?ConnectionManager $connectionResolver = null;

    /** @var array<class-string, array<int, Closure>> */
    protected static array $makers = [];

    /** @var array<class-string, array<string, array<int, Closure>>> */
    protected static array $events = [];

    /** 已解析的数据表字段元信息缓存 */
    protected static array $tableFieldsCache = [];

    /** 已解析的 PHPDoc @property 注解缓存，供 ide-helper 复用 */
    protected static array $modelMetaCache = [];

    // ── 子类覆盖项 ─────────────────────────────────────────────────────

    /** 连接名；为空则使用默认连接 */
    protected string $connection = '';

    /** 完整数据表名（含前缀）；为空时按 name 或类名推断 */
    protected string $table = '';

    /** 不含前缀的表名（将自动拼接连接的 prefix） */
    protected string $name = '';

    /** 表名后缀（用于分表） */
    protected string $suffix = '';

    /** 主键字段名 */
    protected string $pk = 'id';

    /** 字段白名单；为空时按数据库表结构自动获取 */
    protected array $field = [];

    /** 废弃字段：查询与写入都会跳过（即使数据库中仍存在） */
    protected array $disuse = [];

    /** 只读字段：仅在 INSERT 时写入，UPDATE 时忽略 */
    protected array $readonly = [];

    /** 隐藏字段：toArray / JSON 序列化时排除 */
    protected array $hidden = [];

    /** 显示字段：toArray / JSON 序列化时的白名单（优先级高于 hidden） */
    protected array $visible = [];

    /** 追加输出字段：会调用 getXxxAttr 作为虚拟字段 */
    protected array $append = [];

    /** 字段类型映射：int/float/bool/string/array/object/json/datetime/timestamp */
    protected array $type = [];

    /** JSON 字段列表（等价于 type 中设 'json'） */
    protected array $json = [];

    /** JSON 解码时是否关联数组 */
    protected bool $jsonAssoc = false;

    /** 自动写入时间戳；true=从 type 推断；'datetime'/'timestamp' 显式指定 */
    protected bool|string $autoWriteTimestamp = true;

    /** 创建时间字段；空串关闭 */
    protected string $createTime = 'create_time';

    /** 更新时间字段；空串关闭 */
    protected string $updateTime = 'update_time';

    /** 时间字段序列化格式 */
    protected string $dateFormat = 'Y-m-d H:i:s';

    // ── 实例状态 ─────────────────────────────────────────────────────────

    /** 当前属性数据 */
    protected array $data = [];

    /** 原始数据库数据（用于 isDirty 对比） */
    protected array $origin = [];

    /** 关联数据缓存 */
    protected array $relation = [];

    /** 实例是否已存在于数据库中 */
    protected bool $exists = false;

    public function __construct(array $data = [])
    {
        if (!empty(static::$makers[static::class])) {
            foreach (static::$makers[static::class] as $maker) {
                $maker($this);
            }
        }

        if ($data !== []) {
            foreach ($data as $k => $v) {
                $this->setAttr((string) $k, $v);
            }
        }
    }

    // ── 连接管理 ─────────────────────────────────────────────────────────

    public static function setConnectionResolver(ConnectionManager $resolver): void
    {
        self::$connectionResolver = $resolver;
    }

    public static function getConnectionResolver(): ConnectionManager
    {
        if (self::$connectionResolver === null) {
            throw new DbException('Model has no ConnectionManager; call Model::setConnectionResolver() first');
        }

        return self::$connectionResolver;
    }

    public function getConnection(): Connection
    {
        return self::getConnectionResolver()->connect($this->connection !== '' ? $this->connection : null);
    }

    /**
     * 注册实例构造钩子。
     *
     * 用途：全局注入通用字段配置（如时间字段名、租户 ID 等）。
     */
    public static function maker(Closure $maker): void
    {
        static::$makers[static::class][] = $maker;
    }

    public function setTimeField(string $createTime, string $updateTime): void
    {
        $this->createTime = $createTime;
        $this->updateTime = $updateTime;
    }

    // ── 元数据 ───────────────────────────────────────────────────────────

    public function getTable(): string
    {
        if ($this->table !== '') {
            return $this->table . $this->suffix;
        }

        $name   = $this->name !== '' ? $this->name : $this->inferTableName();
        $prefix = (string) $this->getConnection()->getConfig('prefix', '');

        return $prefix . $name . $this->suffix;
    }

    protected function inferTableName(): string
    {
        $class = static::class;
        $short = substr($class, (int) strrpos($class, '\\') + 1);

        // 驼峰转蛇形
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
    }

    public function getPk(): string
    {
        return $this->pk;
    }

    public function getKey(): mixed
    {
        return $this->data[$this->pk] ?? null;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * 获取可查询的字段列表（扣除 $disuse）。
     *
     * - 若模型显式配置 $field，使用其值；
     * - 否则从数据库表结构自动获取（结果缓存在 static::$tableFieldsCache）。
     *
     * @return array<int, string>
     */
    public function getActiveFields(): array
    {
        $all = $this->field;
        if ($all === []) {
            $table = $this->getTable();
            $key   = $this->connection . ':' . $table;
            if (!isset(self::$tableFieldsCache[$key])) {
                self::$tableFieldsCache[$key] = $this->getConnection()->getTableFields($table);
            }
            $all = self::$tableFieldsCache[$key];
        }

        if ($this->disuse === []) {
            return $all;
        }

        return array_values(array_diff($all, $this->disuse));
    }

    // ── Query 入口 ───────────────────────────────────────────────────────

    /**
     * 返回一个绑定了当前模型的新查询构造器。
     */
    public static function query(): Query
    {
        $instance = new static();

        return $instance->newQuery();
    }

    /**
     * 返回绑定当前实例的新查询构造器（含软删除全局过滤）。
     */
    public function newQuery(): Query
    {
        $query = new Query($this->getConnection());
        $query->setModel($this);

        if (method_exists($this, 'applySoftDeleteScope')) {
            $this->{'applySoftDeleteScope'}($query);
        }

        return $query;
    }

    /**
     * {@see newQuery} 的别名，兼容 think-orm 的 `$model->db()`。
     */
    public function db(): Query
    {
        return $this->newQuery();
    }

    /**
     * @param array<int, string>|string $relations
     */
    public static function with(array|string $relations): Query
    {
        return static::query()->with($relations);
    }

    public static function find(mixed $id): ?static
    {
        /** @var static|null $result */
        $result = static::query()->find($id);

        return $result;
    }

    public static function findOrFail(mixed $id): static
    {
        /** @var static $result */
        $result = static::query()->findOrFail($id);

        return $result;
    }

    /**
     * 创建并保存一条新记录。
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): static
    {
        $model = new static($data);
        $model->save();

        return $model;
    }

    /**
     * 按主键批量删除。
     *
     * @param array<int, mixed>|mixed $ids
     */
    public static function destroy(mixed $ids): int
    {
        $ids = is_array($ids) ? $ids : [$ids];
        if ($ids === []) {
            return 0;
        }

        $count = 0;
        foreach (static::query()->whereIn((new static())->getPk(), $ids)->select() as $model) {
            if ($model instanceof Model && $model->delete()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 静态方法代理到查询构造器。
     *
     * 允许写出 `User::where(...)->find()` 这种风格。
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return static::query()->{$method}(...$arguments);
    }

    // ── 内部构造（由 Query 调用） ────────────────────────────────────────

    /**
     * 从数据库行创建模型实例（供 Query 使用）。
     *
     * @param array<string, mixed> $row
     */
    public static function createFromDatabase(array $row): static
    {
        $model         = new static();
        $model->exists = true;
        foreach ($row as $k => $v) {
            // 原始值不走 mutator
            $model->data[$k]   = $v;
            $model->origin[$k] = $v;
        }

        return $model;
    }

    /**
     * 预加载关联数据（供 Query 使用）。
     *
     * @param array<int, static> $models
     * @param array<int, string> $relations
     */
    public static function eagerLoadRelations(array $models, array $relations): void
    {
        if ($models === [] || $relations === []) {
            return;
        }

        foreach ($relations as $relation) {
            // 子关联暂不支持点号嵌套，统一取顶层
            $name = str_contains($relation, '.') ? strstr($relation, '.', true) : $relation;
            if (!$name || !method_exists($models[0], (string) $name)) {
                continue;
            }
            /** @var Relation $relationObj */
            $relationObj = $models[0]->{$name}();
            if (!$relationObj instanceof Relation) {
                continue;
            }
            $relationObj->eagerLoad($models, (string) $name);
        }
    }

    // ── 属性读写 ─────────────────────────────────────────────────────────

    public function hasAttr(string $name): bool
    {
        return array_key_exists($name, $this->data);
    }

    public function getAttr(string $name): mixed
    {
        // accessor：getXxxAttr($value, $data)
        $method = 'get' . $this->studly($name) . 'Attr';
        $value  = $this->data[$name] ?? null;

        if (method_exists($this, $method)) {
            return $this->{$method}($this->readTransform($name, $value), $this->data);
        }

        if ($name === $this->pk && $value === null && isset($this->data[$this->pk])) {
            return $this->data[$this->pk];
        }

        return $this->readTransform($name, $value);
    }

    public function setAttr(string $name, mixed $value): void
    {
        // mutator：setXxxAttr($value, $data)
        $method = 'set' . $this->studly($name) . 'Attr';
        if (method_exists($this, $method)) {
            $value = $this->{$method}($value, $this->data);
        }

        $this->data[$name] = $this->writeTransform($name, $value);
    }

    /**
     * 类型转换（数据库读取时）。
     */
    protected function readTransform(string $name, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $this->getFieldType($name);
        if ($type === null) {
            return $value;
        }

        return match ($type) {
            'int', 'integer'  => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'string'          => (string) $value,
            'array'           => is_string($value) ? (array) json_decode($value, true) : (array) $value,
            'object'          => is_string($value) ? json_decode($value) : $value,
            'json'            => is_string($value) ? json_decode($value, $this->jsonAssoc) : $value,
            'timestamp'       => is_numeric($value) ? date($this->dateFormat, (int) $value) : $value,
            'datetime'        => $value,
            default           => $value,
        };
    }

    /**
     * 类型转换（数据库写入时）。
     */
    protected function writeTransform(string $name, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $this->getFieldType($name);
        if ($type === null) {
            return $value;
        }

        return match ($type) {
            'int', 'integer'          => (int) $value,
            'float', 'double'         => (float) $value,
            'bool', 'boolean'         => $value ? 1 : 0,
            'string'                  => (string) $value,
            'array', 'object', 'json' => is_array($value) || is_object($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE)
                : $value,
            'timestamp'               => is_numeric($value) ? (int) $value : strtotime((string) $value),
            default                   => $value,
        };
    }

    protected function getFieldType(string $name): ?string
    {
        if (isset($this->type[$name])) {
            return (string) $this->type[$name];
        }
        if (in_array($name, $this->json, true)) {
            return 'json';
        }

        return null;
    }

    public function __get(string $name): mixed
    {
        // 关联缓存
        if (array_key_exists($name, $this->relation)) {
            return $this->relation[$name];
        }

        // 关联方法：lazy 加载
        if (method_exists($this, $name) && !$this->hasAttr($name)) {
            $relation = $this->{$name}();
            if ($relation instanceof Relation) {
                return $this->relation[$name] = $relation->getResults();
            }
        }

        return $this->getAttr($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->setAttr($name, $value);
    }

    public function __isset(string $name): bool
    {
        return $this->hasAttr($name) || array_key_exists($name, $this->relation);
    }

    public function __unset(string $name): void
    {
        unset($this->data[$name], $this->relation[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->__isset((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->__get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->__set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->__unset((string) $offset);
    }

    // ── 展示控制 ─────────────────────────────────────────────────────────

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

    /**
     * @param array<int, string>|string $attrs
     */
    public function append(array|string $attrs): static
    {
        $attrs        = is_array($attrs) ? $attrs : [$attrs];
        $this->append = array_values(array_unique(array_merge($this->append, $attrs)));

        return $this;
    }

    /**
     * 设置关联缓存（供 Relation 预加载后回填）。
     */
    public function setRelation(string $name, mixed $value): void
    {
        $this->relation[$name] = $value;
    }

    public function getRelation(string $name): mixed
    {
        return $this->relation[$name] ?? null;
    }

    // ── 序列化 ───────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        foreach ($this->data as $key => $value) {
            $data[$key] = $this->getAttr($key);
        }

        // append 虚拟字段
        foreach ($this->append as $key) {
            $data[$key] = $this->getAttr($key);
        }

        // 关联数据
        foreach ($this->relation as $key => $value) {
            if ($value instanceof Model) {
                $data[$key] = $value->toArray();
            } elseif ($value instanceof Collection) {
                $data[$key] = $value->toArray();
            } elseif (is_array($value)) {
                $data[$key] = array_map(
                    static fn ($v) => $v instanceof Model ? $v->toArray() : $v,
                    $value,
                );
            } else {
                $data[$key] = $value;
            }
        }

        // 废弃字段过滤
        if ($this->disuse !== []) {
            foreach ($this->disuse as $k) {
                unset($data[$k]);
            }
        }

        // visible 优先于 hidden
        if ($this->visible !== []) {
            $data = array_intersect_key($data, array_flip($this->visible));
        } elseif ($this->hidden !== []) {
            $data = array_diff_key($data, array_flip($this->hidden));
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }

    // ── 写入 ─────────────────────────────────────────────────────────────

    /**
     * 保存模型（新增或更新）。
     *
     * @param array<string, mixed> $attributes
     */
    public function save(array $attributes = []): bool
    {
        foreach ($attributes as $k => $v) {
            $this->setAttr((string) $k, $v);
        }

        if ($this->fireEvent('before_write') === false) {
            return false;
        }

        $result = $this->exists ? $this->performUpdate() : $this->performInsert();

        if ($result) {
            $this->fireEvent('after_write');
        }

        return $result;
    }

    protected function performInsert(): bool
    {
        if ($this->fireEvent('before_insert') === false) {
            return false;
        }

        $data = $this->getDataForWrite();

        // 自动时间戳
        $now = $this->nowTimestampValue();
        if ($this->autoWriteTimestamp !== false) {
            if ($this->createTime !== '' && !array_key_exists($this->createTime, $data)) {
                $data[$this->createTime]       = $now;
                $this->data[$this->createTime] = $now;
            }
            if ($this->updateTime !== '' && !array_key_exists($this->updateTime, $data)) {
                $data[$this->updateTime]       = $now;
                $this->data[$this->updateTime] = $now;
            }
        }

        if ($data === []) {
            return false;
        }

        $query = $this->newQueryForWrite();
        $id    = $query->insertGetId($data);

        // 若主键字段为空，回填自增 ID
        if (!isset($this->data[$this->pk]) || $this->data[$this->pk] === null) {
            if (is_numeric($id)) {
                $this->data[$this->pk] = (int) $id;
            } else {
                $this->data[$this->pk] = $id;
            }
        }

        $this->exists = true;
        $this->origin = $this->data;

        $this->fireEvent('after_insert');

        return true;
    }

    protected function performUpdate(): bool
    {
        if ($this->fireEvent('before_update') === false) {
            return false;
        }

        $data = $this->getDataForWrite(true);

        // 去除 readonly
        foreach ($this->readonly as $k) {
            unset($data[$k]);
        }

        // dirty 检测
        $dirty = [];
        foreach ($data as $k => $v) {
            if (!array_key_exists($k, $this->origin) || $this->origin[$k] !== $v) {
                $dirty[$k] = $v;
            }
        }

        if ($dirty === []) {
            return true;
        }

        // 自动更新时间
        if ($this->autoWriteTimestamp !== false && $this->updateTime !== ''
                                                && !array_key_exists($this->updateTime, $dirty)) {
            $now                           = $this->nowTimestampValue();
            $dirty[$this->updateTime]      = $now;
            $this->data[$this->updateTime] = $now;
        }

        $pk = $this->getKey();
        if ($pk === null) {
            throw new DbException('cannot update a model without primary key');
        }

        $query = $this->newQueryForWrite();
        $query->where($this->pk, $pk)->update($dirty);

        $this->origin = $this->data;

        $this->fireEvent('after_update');

        return true;
    }

    /**
     * 删除模型。
     *
     * 启用 SoftDelete trait 时写入 $deleteTime 字段，否则真删除。
     */
    public function delete(): bool
    {
        if ($this->fireEvent('before_delete') === false) {
            return false;
        }

        if (method_exists($this, 'runSoftDelete')) {
            $result = (bool) $this->{'runSoftDelete'}();
        } else {
            $pk = $this->getKey();
            if ($pk === null) {
                return false;
            }
            $result = $this->newQueryForWrite()->where($this->pk, $pk)->delete() > 0;
            if ($result) {
                $this->exists = false;
            }
        }

        if ($result) {
            $this->fireEvent('after_delete');
        }

        return $result;
    }

    /**
     * 强制删除（无论是否 SoftDelete 都真实删除）。
     */
    public function forceDelete(): bool
    {
        $pk = $this->getKey();
        if ($pk === null) {
            return false;
        }

        $query = $this->newQueryForWrite();
        if (method_exists($this, 'getDeleteTimeField')) {
            $query->withTrashed();
        }
        $result = $query->where($this->pk, $pk)->delete() > 0;
        if ($result) {
            $this->exists = false;
        }

        return $result;
    }

    /**
     * 构建用于写入的 Query（跳过软删除全局过滤）。
     */
    protected function newQueryForWrite(): Query
    {
        $query = new Query($this->getConnection());
        $query->setModel($this);

        // 写入场景不需要软删除过滤
        return $query;
    }

    /**
     * 导出用于写入数据库的数据（扣除 disuse 与不属于表字段的键）。
     *
     * @return array<string, mixed>
     */
    protected function getDataForWrite(bool $forUpdate = false): array
    {
        $fields = $this->getActiveFields();
        $allow  = $fields === [] ? array_keys($this->data) : $fields;

        $data = [];
        foreach ($allow as $field) {
            if (array_key_exists($field, $this->data)) {
                $data[$field] = $this->data[$field];
            }
        }

        return $data;
    }

    protected function nowTimestampValue(): mixed
    {
        $mode = $this->autoWriteTimestamp;
        if ($mode === 'timestamp' || $mode === 'int' || $mode === 'integer') {
            return time();
        }

        return date($this->dateFormat);
    }

    // ── 关联定义 ─────────────────────────────────────────────────────────

    /**
     * @template TRelated of Model
     * @param  class-string<TRelated> $related
     * @return HasOne<TRelated>
     */
    protected function hasOne(string $related, string $foreignKey = '', string $localKey = ''): HasOne
    {
        $localKey   = $localKey   !== '' ? $localKey : $this->pk;
        $foreignKey = $foreignKey !== '' ? $foreignKey : $this->defaultForeignKey();

        return new HasOne($this, $related, $foreignKey, $localKey);
    }

    /**
     * @template TRelated of Model
     * @param  class-string<TRelated> $related
     * @return HasMany<TRelated>
     */
    protected function hasMany(string $related, string $foreignKey = '', string $localKey = ''): HasMany
    {
        $localKey   = $localKey   !== '' ? $localKey : $this->pk;
        $foreignKey = $foreignKey !== '' ? $foreignKey : $this->defaultForeignKey();

        return new HasMany($this, $related, $foreignKey, $localKey);
    }

    /**
     * @template TRelated of Model
     * @param  class-string<TRelated> $related
     * @return BelongsTo<TRelated>
     */
    protected function belongsTo(string $related, string $foreignKey = '', string $ownerKey = ''): BelongsTo
    {
        $ownerKey   = $ownerKey   !== '' ? $ownerKey : (new $related())->getPk();
        $foreignKey = $foreignKey !== '' ? $foreignKey : $this->defaultForeignKey($related);

        return new BelongsTo($this, $related, $foreignKey, $ownerKey);
    }

    protected function defaultForeignKey(?string $relatedClass = null): string
    {
        $target = $relatedClass ?? static::class;
        $short  = substr($target, (int) strrpos($target, '\\') + 1);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short)) . '_id';
    }

    // ── 事件 ─────────────────────────────────────────────────────────────

    /**
     * 注册模型事件（闭包方式）。
     *
     * 支持事件：before_write / after_write / before_insert / after_insert /
     * before_update / after_update / before_delete / after_delete。
     */
    public static function event(string $event, Closure $callback): void
    {
        self::$events[static::class][$event][] = $callback;
    }

    /**
     * 触发模型事件。
     *
     * 调用顺序：先调用模型内定义的静态方法 `on{Studly(event)}`，再调用通过 event() 注册的闭包。
     * 任一回调返回 false 则中断后续回调并返回 false（通常用于拦截 before_* 事件）。
     */
    protected function fireEvent(string $event): bool
    {
        // 1. 静态方法形式：onBeforeInsert / onAfterUpdate / ...
        $method = 'on' . $this->studly($event);
        if (method_exists(static::class, $method)) {
            $result = static::$method($this);
            if ($result === false) {
                return false;
            }
        }

        // 2. 通过 event() 注册的闭包
        foreach (self::$events[static::class][$event] ?? [] as $cb) {
            if ($cb($this) === false) {
                return false;
            }
        }

        return true;
    }

    // ── 工具 ─────────────────────────────────────────────────────────────

    protected function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getOrigin(): array
    {
        return $this->origin;
    }

    public function isDirty(?string $field = null): bool
    {
        if ($field !== null) {
            return array_key_exists($field, $this->data)
                && (!array_key_exists($field, $this->origin) || $this->origin[$field] !== $this->data[$field]);
        }

        foreach ($this->data as $k => $v) {
            if (!array_key_exists($k, $this->origin) || $this->origin[$k] !== $v) {
                return true;
            }
        }

        return false;
    }
}
