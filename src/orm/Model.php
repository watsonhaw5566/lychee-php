<?php

declare(strict_types=1);

namespace Lychee\orm;

use ArrayAccess;
use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use Lychee\orm\attribute\Append;
use Lychee\orm\attribute\Column;
use Lychee\orm\attribute\Getter;
use Lychee\orm\attribute\Setter;
use Lychee\orm\exception\DbException;
use Lychee\orm\relation\BelongsTo;
use Lychee\orm\relation\BelongsToMany;
use Lychee\orm\relation\HasMany;
use Lychee\orm\relation\HasOne;
use Lychee\orm\relation\Relation;
use Throwable;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use UnitEnum;

/**
 * ORM 模型基类（Active Record）。
 *
 * 用法与 ThinkPHP Model 对齐：
 *
 *   class User extends Model {
 *       protected string $table = 'user';
 *       protected array $disuse = ['legacy_col'];
 *
 *       #[Column(type: 'decimal:2')]
 *       protected float $balance;
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
 * @method static Query whereNotExists(Closure $callback)
 * @method static Query whereColumn(string $field, string $op, string $field2 = null)
 * @method static Query whereTime(string $field, string $expression)
 * @method static Query when(mixed $condition, callable $callback, ?callable $default = null)
 * @method static Query field(string|array $fields)
 * @method static Query order(string|array|Raw $field, string $direction = 'ASC')
 * @method static Query limit(int $limit, ?int $offset = null)
 * @method static Query page(int $page, int $pageSize)
 * @method static Query group(string $group)
 * @method static Query having(string $having, array $bindings = [])
 * @method static Query join(string $table, string $condition, string $type = 'INNER')
 * @method static Query leftJoin(string $table, string $condition)
 * @method static Query rightJoin(string $table, string $condition)
 * @method static Query withCount(string|array $relations)
 * @method static Query append(string|array $attrs)
 * @method static Query hidden(array $fields)
 * @method static Query visible(array $fields)
 * @method static Query withTrashed()
 * @method static Query onlyTrashed()
 * @method static Query failException(bool $fail = true)
 * @method static Query alias(string $alias)
 * @method static Query distinct(bool $enable = true)
 * @method static Query lock(bool|string $lock = true)
 * @method static Query table(string $table)
 * @method static Query name(string $name)
 *
 * 以下方法来自 Query 类，通过 Model::__callStatic 魔术转发。
 * 显式声明以让 phpstan 识别静态调用。
 *
 * @method static Collection|array select()
 * @method static mixed value(string $field)
 * @method static array column(string $field, ?string $key = null)
 * @method static Paginator paginate(int $page = 1, int $pageSize = 15, bool $simple = false)
 * @method static bool chunk(int $size, callable $callback)
 * @method static bool chunkById(int $size, callable $callback, ?string $column = null)
 * @method static \Generator cursor()
 * @method static bool exists()
 * @method static int count(string $field = '*')
 * @method static int|float sum(string $field)
 * @method static float avg(string $field)
 * @method static mixed min(string $field)
 * @method static mixed max(string $field)
 * @method static int insert(array $data)
 * @method static int|string insertGetId(array $data)
 * @method static int insertAll(array $rows)
 * @method static int upsert(array $rows, array $uniqueBy = [], array $update = [])
 * @method static int update(array $data)
 * @method static int delete()
 * @method static int forceDelete()
 * @method static int inc(string $field, int|float $step = 1)
 * @method static int dec(string $field, int|float $step = 1)
 * @method static static firstOrCreate(array $attributes, array $values = [])
 * @method static static updateOrCreate(array $attributes, array $values = [])
 *
 * 以下方法来自 SoftDelete trait，仅在模型使用该 trait 时可用。
 * 显式声明以让 phpstan 识别 Query 中的调用。
 *
 * @method string getDeleteTimeField()
 * @method mixed getSoftDeleteValue()
 * @method mixed getDefaultSoftDelete()
 * @method bool runSoftDelete()
 * @method void applySoftDeleteScope(Query $query)
 * @method bool trashed()
 * @method bool restore()
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

    /** 字段类型推导结果缓存，键为 'connection:table:field'，值为 ORM 标准类型或 'null' 标记 */
    protected static array $typeInferenceCache = [];

    /** 已解析的 PHPDoc @property 注解缓存，供 ide-helper 复用 */
    protected static array $modelMetaCache = [];

    /**
     * #[Column] 属性扫描结果缓存，键为模型类名，值为 ['属性名' => Column 实例]。
     *
     * @var array<class-string, array<string, Column>>
     */
    protected static array $columnAttributeCache = [];

    /**
     * #[Append] 属性扫描结果缓存，键为模型类名，值为属性名列表。
     *
     * @var array<class-string, array<int, string>>
     */
    protected static array $appendAttributeCache = [];

    /**
     * 模型类 → #[Append(with: true)] 关联方法名列表缓存。
     *
     * @var array<class-string, array<int, string>>
     */
    protected static array $appendWithCache = [];

    /**
     * #[Getter] 方法扫描结果缓存，键为模型类名，值为 ['字段名' => '方法名']。
     *
     * @var array<class-string, array<string, string>>
     */
    protected static array $getterMethodCache = [];

    /**
     * #[Setter] 方法扫描结果缓存，键为模型类名，值为 ['字段名' => '方法名']。
     *
     * @var array<class-string, array<string, string>>
     */
    protected static array $setterMethodCache = [];

    // ── 子类覆盖项 ─────────────────────────────────────────────────────

    /** 连接名；为空则使用默认连接 */
    protected string $connection = '';

    /** 完整数据表名（含前缀）；为空时按 name 或类名推断 */
    protected string $table = '';

    /** 不含前缀的表名（将自动拼接连接的 prefix）；为空时从类名自动推断 */
    protected string $name = '';

    /** 表名后缀（用于分表） */
    protected string $suffix = '';

    /** 主键字段名 */
    protected string $pk = 'id';

    /**
     * 模型配置集中存放，避免与数据库字段同名冲突（如 hidden 等）。
     *
     * 子类可通过声明同名属性（如 protected array $hidden = [...]）进行配置，
     * 构造时会自动合并到本数组；也可直接覆盖 $modelConfig。
     *
     * @var array{
     *     field:array<int,string>,
     *     disuse:array<int,string>,
     *     readonly:array<int,string>,
     *     hidden:array<int,string>,
     *     visible:array<int,string>,
     * }
     */
    protected array $modelConfig = [
        'field'    => [],
        'disuse'   => [],
        'readonly' => [],
        'hidden'   => [],
        'visible'  => [],
    ];

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

    /**
     * 级联删除的关联方法名列表。
     *
     * 删除当前模型时，会按顺序自动删除这些关联的记录：
     *   - HasMany：遍历所有子记录并逐个调用 delete() / forceDelete()
     *   - HasOne / BelongsTo：删除单条关联记录
     *
     * 子记录的删除会触发其自身的 before_delete / after_delete 事件，
     * 并递归处理子模型的 $cascade（嵌套级联）。
     *
     * 用法：
     *   protected array $cascade = ['orders', 'profile'];
     *
     *   public function orders(): HasMany { return $this->hasMany(Order::class); }
     *   public function profile(): HasOne { return $this->hasOne(Profile::class); }
     *
     * 整个删除流程（级联 + 自身）会在一个事务内执行，任一子记录删除失败
     * （如 before_delete 返回 false）都会回滚，避免产生孤儿数据。
     *
     * @var array<int, string>
     */
    protected array $cascade = [];

    // ── 实例状态 ─────────────────────────────────────────────────────────

    /** 当前属性数据 */
    protected array $data = [];

    /** 原始数据库数据（用于 isDirty 对比） */
    protected array $origin = [];

    /** 关联数据缓存 */
    protected array $relation = [];

    /** 实例是否已存在于数据库中 */
    protected bool $exists = false;

    /** 子类声明的配置属性名缓存（用于构造时合并到 $modelConfig） */
    protected static array $declaredConfigCache = [];

    /** 反射扫描得到的关联方法名缓存，键为模型类名 */
    protected static array $relationNamesCache = [];

    public function __construct(array $data = [])
    {
        $this->mergeDeclaredConfig();

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

    /**
     * 将子类通过 `protected array $hidden = [...]` 等方式声明的配置属性
     * 合并到 $modelConfig，保持向后兼容。
     *
     * 仅在首次实例化该子类时反射一次，结果按类缓存。
     */
    protected function mergeDeclaredConfig(): void
    {
        $class = static::class;

        if (!isset(self::$declaredConfigCache[$class])) {
            $names = array_keys($this->modelConfig);
            $ref   = new ReflectionClass($class);
            $found = [];
            foreach ($names as $name) {
                if ($ref->hasProperty($name)) {
                    $found[] = $name;
                }
            }
            self::$declaredConfigCache[$class] = $found;
        }

        foreach (self::$declaredConfigCache[$class] as $name) {
            if (property_exists($this, $name) && is_array($this->{$name})) {
                $this->modelConfig[$name] = array_merge($this->modelConfig[$name], $this->{$name});
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
     * 在当前模型所属连接上执行事务闭包。
     *
     * 用法：Booking::transaction(fn () => ...)
     */
    public static function transaction(Closure $callback): mixed
    {
        return (new static())->getConnection()->transaction($callback);
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
        $pos   = strrpos($class, '\\');
        $short = $pos === false ? $class : substr($class, $pos + 1);

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
        $all = $this->modelConfig['field'];
        if ($all === []) {
            $table = $this->getTable();
            $key   = $this->connection . ':' . $table;
            if (!isset(self::$tableFieldsCache[$key])) {
                self::$tableFieldsCache[$key] = $this->getConnection()->getTableFields($table);
            }
            $all = self::$tableFieldsCache[$key];
        }

        if ($this->modelConfig['disuse'] === []) {
            return $all;
        }

        return array_values(array_diff($all, $this->modelConfig['disuse']));
    }

    /**
     * 获取模型声明的 JSON 字段列表。
     *
     * 返回通过 #[Column(json: true)] 或 #[Column(type: 'json')] 声明的 JSON 字段。
     *
     * 供外部（如控制器的查询 DSL）判断某字段是否为 JSON 数组字段，
     * 避免使用反射直接读取 protected 属性。
     *
     * @return array<int, string>
     */
    public function getJsonFields(): array
    {
        $fields = [];

        foreach ($this->getColumnMeta() as $name => $column) {
            if ($column->json || $column->type === 'json') {
                $fields[] = $name;
            }
        }

        return $fields;
    }

    // ── Query 入口 ───────────────────────────────────────────────────────

    /**
     * 返回一个绑定了当前模型的新查询构造器。
     *
     * @return Query<static>
     */
    public static function query(): Query
    {
        $instance = new static();

        return $instance->newQuery();
    }

    /**
     * 返回绑定当前实例的新查询构造器（含软删除全局过滤）。
     *
     * @return Query<static>
     */
    public function newQuery(): Query
    {
        /** @var Query<static> $query */
        $query = new Query($this->getConnection());
        $query->setModel($this);

        if (method_exists($this, 'applySoftDeleteScope')) {
            $this->{'applySoftDeleteScope'}($query);
        }

        // 自动预加载 #[Append] 声明的关联，避免列表 N+1
        $withRelations = $this->getAppendWithRelations();
        if ($withRelations !== []) {
            $query->with($withRelations);
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
     * 递增模型的某个字段值。
     *
     * @return int 受影响行数（>0 表示成功）
     */
    public function increment(string $field, int|float $step = 1): int
    {
        $pk = $this->getKey();
        if ($pk === null) {
            return 0;
        }

        $affected = $this->newQueryForWrite()->where($this->pk, $pk)->inc($field, $step);
        if ($affected > 0 && array_key_exists($field, $this->data)) {
            $this->data[$field] = ($this->data[$field] ?? 0) + $step;
        }

        return $affected;
    }

    /**
     * 递减模型的某个字段值。
     *
     * @return int 受影响行数（>0 表示成功）
     */
    public function decrement(string $field, int|float $step = 1): int
    {
        return $this->increment($field, -$step);
    }

    /**
     * 从数据库重新加载当前模型，返回新实例（不修改自身）。
     */
    public function fresh(): ?static
    {
        $pk = $this->getKey();
        if ($pk === null) {
            return null;
        }

        /** @var static|null $result */
        $result = static::query()->find($pk);

        return $result;
    }

    /**
     * 从数据库重新加载当前模型并刷新自身数据。
     */
    public function refresh(): static
    {
        $fresh = $this->fresh();
        if ($fresh !== null) {
            $this->data     = $fresh->data;
            $this->origin   = $fresh->origin;
            $this->exists   = $fresh->exists;
            $this->relation = [];
        }

        return $this;
    }

    /**
     * @param array<int, string>|string $relations
     * @return Query<static>
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
     * 按主键或条件批量删除。
     *
     * 用法（与 ThinkPHP 对齐）：
     *   User::destroy(1);                 // 按主键删除
     *   User::destroy([1, 2, 3]);         // 按主键列表删除
     *   User::destroy(['status' => 0]);   // 按条件删除（关联数组视为 where 条件）
     *   User::destroy(1, true);           // 强制硬删除（忽略软删除）
     *
     * 启用 SoftDelete trait 时默认走软删除，$force = true 时真实删除。
     * 每个实例都会触发 before_delete / after_delete 事件。
     *
     * 批量删除在一个事务内执行：若任一实例（含其级联关联）删除失败，
     * 整个批次回滚。
     *
     * @param mixed $ids   主键、主键数组、或条件数组（关联数组）
     * @param bool  $force true 时强制硬删除
     * @return int 成功删除的记录数
     */
    public static function destroy(mixed $ids, bool $force = false): int
    {
        if (empty($ids)) {
            return 0;
        }

        $instance = new static();
        $query    = static::query();

        // 关联数组视为 where 条件；否则按主键删除
        if (is_array($ids) && key($ids) !== 0 && key($ids) !== null) {
            foreach ($ids as $field => $value) {
                $query->where($field, $value);
            }
        } else {
            $idList = is_array($ids) ? $ids : [$ids];
            $query->whereIn($instance->getPk(), $idList);
        }

        $count = 0;
        $instance->getConnection()->transaction(function () use ($query, $force, &$count): void {
            foreach ($query->select() as $model) {
                if (!$model instanceof Model) {
                    continue;
                }
                $result = $force ? $model->forceDelete() : $model->delete();
                if ($result) {
                    $count++;
                }
            }
        });

        return $count;
    }

    /**
     * 批量保存模型实例（自动区分新增 / 更新），在事务内执行。
     *
     * 每个实例的 $exists 属性决定走 insert 还是 update，单个 save() 的事件
     * （before_insert / after_update 等）照常触发。
     *
     * @param array<int, static> $models
     * @return array<int, static> 保存后的模型实例（与入参同序）
     */
    public static function saveAll(array $models): array
    {
        if ($models === []) {
            return [];
        }

        (new static())->getConnection()->transaction(function () use ($models): void {
            foreach ($models as $model) {
                if ($model instanceof static) {
                    $model->save();
                }
            }
        });

        return $models;
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

    /**
     * 实例方法代理到查询构造器。
     *
     * 允许写出 `$user->where(...)->select()` 这种风格，与容器注入的模型实例配合使用。
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->newQuery()->{$method}(...$arguments);
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
            if (!$name) {
                continue;
            }
            // 支持蛇形 / 驼峰关联名
            $method = $models[0]->resolveRelationMethod((string) $name);
            if ($method === null) {
                continue;
            }
            /** @var Relation $relationObj */
            $relationObj = $models[0]->{$method}();
            if (!$relationObj instanceof Relation) {
                continue;
            }
            // 缓存键使用原始关联名，保证 toArray 输出 key 与传入一致
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
        $value = $this->data[$name] ?? null;

        // 优先使用 #[Getter] 显式绑定的访问器方法
        $getters = $this->getGetterMethods();
        if (isset($getters[$name])) {
            return $this->{$getters[$name]}($this->readTransform($name, $value), $this->data);
        }

        // 回退：getXxxAttr 命名约定
        $method = 'get' . $this->studly($name) . 'Attr';
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
        // 优先使用 #[Setter] 显式绑定的修改器方法
        $setters = $this->getSetterMethods();
        if (isset($setters[$name])) {
            $value = $this->{$setters[$name]}($value, $this->data);
        } else {
            // 回退：setXxxAttr 命名约定
            $method = 'set' . $this->studly($name) . 'Attr';
            if (method_exists($this, $method)) {
                $value = $this->{$method}($value, $this->data);
            }
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

        $info = $this->parseType($type);

        return match ($info['kind']) {
            'int', 'integer'  => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'string'          => (string) $value,
            'array'           => is_string($value) ? (array) json_decode($value, true) : (array) $value,
            'object'          => is_string($value) ? json_decode($value) : $value,
            'json'            => is_string($value) ? json_decode($value, $this->jsonAssoc) : $value,
            'timestamp'       => is_numeric($value) ? date($this->dateFormat, (int) $value) : $value,
            'datetime'        => $value,
            'decimal'         => $this->readDecimal($value, (int) $info['param']),
            'date'            => $this->readDate($value, (string) $info['param']),
            'enum'            => $this->readEnum($value, (string) $info['class']),
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

        $info = $this->parseType($type);

        return match ($info['kind']) {
            'int', 'integer'          => (int) $value,
            'float', 'double'         => (float) $value,
            'bool', 'boolean'         => $value ? 1 : 0,
            'string'                  => (string) $value,
            'array', 'object', 'json' => is_array($value) || is_object($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE)
                : $value,
            'timestamp'               => is_numeric($value) ? (int) $value : strtotime((string) $value),
            'datetime'                => $value,
            'decimal'                 => $this->writeDecimal($value, (int) $info['param']),
            'date'                    => $this->writeDate($value, (string) $info['param']),
            'enum'                    => $this->writeEnum($value),
            default                   => $value,
        };
    }

    protected function getFieldType(string $name): ?string
    {
        // 1. 优先使用 #[Column] 属性声明
        $meta = $this->getColumnMeta();
        if (isset($meta[$name])) {
            $column = $meta[$name];
            if ($column->type !== null) {
                return $column->type;
            }
            if ($column->json) {
                return 'json';
            }
        }

        // 2. 从数据库元信息自动推导类型
        $inferred = $this->inferFieldType($name);
        if ($inferred !== null) {
            return $inferred;
        }

        // 3. 约定俗成的通用字段默认类型（id / create_time / update_time / delete_time）
        return $this->getConventionFieldType($name);
    }

    /**
     * 约定俗成的通用字段默认类型。
     *
     * 避免每个模型都为 id / create_time / update_time / delete_time
     * 重复声明 #[Column]。这些字段按框架约定自动获得类型转换：
     *   - 主键（$pk）→ 'int'
     *   - 创建时间（$createTime）→ 'datetime'
     *   - 更新时间（$updateTime）→ 'datetime'
     *   - 软删除时间（SoftDelete::$deleteTime）→ 'datetime'
     *
     * 返回 null 表示该字段无约定默认类型（交由调用方处理）。
     */
    protected function getConventionFieldType(string $name): ?string
    {
        if ($name === $this->pk) {
            return 'int';
        }

        if ($name === $this->createTime || $name === $this->updateTime) {
            return 'datetime';
        }

        if (method_exists($this, 'getDeleteTimeField') && $name === $this->getDeleteTimeField()) {
            return 'datetime';
        }

        return null;
    }

    /**
     * 扫描模型中所有标注了 #[Column] 的属性。
     *
     * 仅收集子类（含继承链中非 Model 基类）声明的属性，跳过 Model 自身的属性。
     * 结果按类缓存，避免重复反射。
     *
     * @return array<string, Column> 键为属性名
     */
    protected function getColumnMeta(): array
    {
        $class = static::class;

        if (!isset(self::$columnAttributeCache[$class])) {
            $meta = [];
            $ref  = new ReflectionClass($class);

            foreach ($ref->getProperties() as $prop) {
                // 跳过 Model 基类自身声明的属性
                if ($prop->getDeclaringClass()->getName() === self::class) {
                    continue;
                }

                $attrs = $prop->getAttributes(Column::class);
                if ($attrs !== []) {
                    $meta[$prop->getName()] = $attrs[0]->newInstance();
                }
            }

            self::$columnAttributeCache[$class] = $meta;
        }

        return self::$columnAttributeCache[$class];
    }

    /**
     * 扫描模型中所有标注了 #[Append] 的属性，返回属性名列表。
     *
     * 这些属性/方法为虚拟追加字段（不对应数据库列），值由访问器计算，
     * 在 `toArray()` 时自动追加到输出。
     *
     * 支持两种声明形式：
     *   1. 属性上标注 #[Append]（需配合 #[Getter] 或 getXxxAttr 提供值）
     *   2. 方法上标注 #[Append]（方法名即字段名，方法本身即为访问器，无需 #[Getter]）
     *
     * 仅收集子类（含继承链中非 Model 基类）声明的属性/方法，跳过 Model 自身。
     * 结果按类缓存，避免重复反射。
     *
     * @return array<int, string> 追加字段名列表
     */
    protected function getAppendMeta(): array
    {
        $class = static::class;

        if (!isset(self::$appendAttributeCache[$class])) {
            $names = [];
            $ref   = new ReflectionClass($class);

            // 形式一：属性上的 #[Append]
            foreach ($ref->getProperties() as $prop) {
                if ($prop->getDeclaringClass()->getName() === self::class) {
                    continue;
                }
                $attrs = $prop->getAttributes(Append::class);
                if ($attrs === []) {
                    continue;
                }
                /** @var Append $instance */
                $instance = $attrs[0]->newInstance();
                $names[]  = $instance->name ?? $prop->getName();
            }

            // 形式二：方法上的 #[Append]（方法名即字段名，方法本身为访问器）
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
                if ($method->getDeclaringClass()->getName() === self::class) {
                    continue;
                }
                $attrs = $method->getAttributes(Append::class);
                if ($attrs === []) {
                    continue;
                }
                /** @var Append $instance */
                $instance = $attrs[0]->newInstance();
                $names[]  = $instance->name ?? $method->getName();
            }

            self::$appendAttributeCache[$class] = array_values(array_unique($names));
        }

        return self::$appendAttributeCache[$class];
    }

    /**
     * 返回标注了 #[Append] 的关联方法名，用于查询时自动预加载，
     * 避免列表场景的 N+1 查询。
     *
     * 所有 #[Append] 关联均自动预加载，无需手动指定。
     *
     * @return array<int, string>
     */
    protected function getAppendWithRelations(): array
    {
        $class = static::class;

        if (!isset(self::$appendWithCache[$class])) {
            $relations = [];
            $ref       = new ReflectionClass($class);

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
                if ($method->getDeclaringClass()->getName() === self::class) {
                    continue;
                }
                $attrs = $method->getAttributes(Append::class);
                if ($attrs === []) {
                    continue;
                }
                /** @var Append $instance */
                $instance = $attrs[0]->newInstance();
                // 仅关联方法（返回 Relation 或其子类）参与预加载
                $returnType = $method->getReturnType();
                if ($returnType instanceof ReflectionNamedType) {
                    $typeName = $returnType->getName();
                    if ($typeName === Relation::class || is_subclass_of($typeName, Relation::class)) {
                        $relations[] = $instance->name ?? $method->getName();
                    }
                }
            }

            self::$appendWithCache[$class] = array_values(array_unique($relations));
        }

        return self::$appendWithCache[$class];
    }

    /**
     * 扫描模型中所有标注了 #[Getter] 的方法，返回字段名 => 方法名映射。
     *
     * 此外，标注了 #[Append] 的方法也自动注册为该字段的访问器
     * （方法名即字段名，方法本身即为 getter，无需再标注 #[Getter]）。
     *
     * 若 #[Getter] 未指定 field 参数，则从方法名推导：
     *   去除 get/set 前缀与 Attr 后缀后首字母小写，或方法名直接等于字段名。
     *   例如 fullName → fullName，getFullNameAttr → fullName。
     *
     * 仅收集子类（含继承链中非 Model 基类）声明的方法，跳过 Model 自身。
     * 结果按类缓存。
     *
     * @return array<string, string> 字段名 => 方法名
     */
    protected function getGetterMethods(): array
    {
        $class = static::class;

        if (!isset(self::$getterMethodCache[$class])) {
            $map = $this->scanAttributeMethods(Getter::class);

            // #[Append] 方法自动作为 getter 注册：输出名(name或方法名) => 方法名
            // 注意：返回 Relation 的关联方法不注册为 getter，由 toArray() 通过
            // resolveRelationMethod() 加载关联数据。
            $ref = new ReflectionClass(static::class);
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
                if ($method->getDeclaringClass()->getName() === self::class) {
                    continue;
                }
                $attrs = $method->getAttributes(Append::class);
                if ($attrs === []) {
                    continue;
                }

                // 跳过关联方法（返回 Relation 或其子类）
                $returnType = $method->getReturnType();
                if ($returnType instanceof ReflectionNamedType) {
                    $typeName = $returnType->getName();
                    if ($typeName === Relation::class || is_subclass_of($typeName, Relation::class)) {
                        continue;
                    }
                }

                /** @var Append $instance */
                $instance = $attrs[0]->newInstance();
                $field    = $instance->name ?? $method->getName();
                // #[Getter] 显式绑定优先级更高
                if (!isset($map[$field])) {
                    $map[$field] = $method->getName();
                }
            }

            self::$getterMethodCache[$class] = $map;
        }

        return self::$getterMethodCache[$class];
    }

    /**
     * 扫描模型中所有标注了 #[Setter] 的方法，返回字段名 => 方法名映射。
     *
     * @return array<string, string> 字段名 => 方法名
     */
    protected function getSetterMethods(): array
    {
        $class = static::class;

        if (!isset(self::$setterMethodCache[$class])) {
            self::$setterMethodCache[$class] = $this->scanAttributeMethods(Setter::class);
        }

        return self::$setterMethodCache[$class];
    }

    /**
     * 扫描标注了指定 Attribute 的方法，推导字段名并返回映射。
     *
     * @param class-string $attributeClass Getter::class 或 Setter::class
     * @return array<string, string> 字段名 => 方法名
     */
    protected function scanAttributeMethods(string $attributeClass): array
    {
        $map = [];
        $ref = new ReflectionClass(static::class);

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
            // 跳过 Model 基类自身声明的方法
            if ($method->getDeclaringClass()->getName() === self::class) {
                continue;
            }

            $attrs = $method->getAttributes($attributeClass);
            if ($attrs === []) {
                continue;
            }

            /** @var Getter|Setter $instance */
            $instance = $attrs[0]->newInstance();
            $field    = $instance->field ?? $this->fieldNameFromMethod($method->getName());

            if ($field !== null) {
                $map[$field] = $method->getName();
            }
        }

        return $map;
    }

    /**
     * 从访问器/修改器方法名推导字段名。
     *
     * 支持三种命名形式（推荐第一种，最简洁）：
     *   1. 方法名直接等于字段名：fullName → fullName
     *   2. get/set 前缀：getFullName → FullName → fullName
     *   3. get/set 前缀 + Attr 后缀：getFullNameAttr → FullName → fullName
     *
     * 若方法名不属于以上形式，返回 null（需用 #[Getter('field')] 显式指定）。
     */
    protected function fieldNameFromMethod(string $method): ?string
    {
        $name = $method;

        if (str_starts_with($name, 'get') || str_starts_with($name, 'set')) {
            $name = substr($name, 3);

            if (str_ends_with($name, 'Attr')) {
                $name = substr($name, 0, -4);
            }

            if ($name === '') {
                return null;
            }

            return lcfirst($name);
        }

        // 无前缀：方法名即为字段名
        return $name === '' ? null : $name;
    }

    /**
     * 应用 #[Column] 声明的默认值。
     *
     * 仅在 INSERT 时调用，且仅对未被显式赋值（不在 $this->data 中）的字段生效。
     * 默认值会经过 writeTransform 转换后写入 $this->data，
     * 保证枚举取 value、数组转 JSON、decimal 格式化等行为与手动赋值一致。
     *
     * `default: null` 视为未声明默认值（不做处理）。
     */
    protected function applyColumnDefaults(): void
    {
        foreach ($this->getColumnMeta() as $name => $column) {
            if ($column->default === null) {
                continue;
            }

            if (array_key_exists($name, $this->data)) {
                continue;
            }

            $this->data[$name] = $this->writeTransform($name, $column->default);
        }
    }

    /**
     * 解析类型字符串，返回统一的描述符。
     *
     * 返回结构：
     *   - 基础类型：['kind' => 'int']
     *   - 参数化：['kind' => 'decimal', 'param' => '2']
     *   - 枚举：  ['kind' => 'enum', 'class' => 'App\\Enum\\UserStatus']
     *
     * @return array{kind:string, param?:string, class?:string}
     */
    protected function parseType(string $type): array
    {
        // 枚举类
        if (enum_exists($type)) {
            return ['kind' => 'enum', 'class' => $type];
        }

        // 参数化类型：decimal:2、date:Y-m-d、datetime:Y-m-d H:i:s
        if (str_contains($type, ':')) {
            [$kind, $param] = explode(':', $type, 2);

            return ['kind' => $kind, 'param' => $param];
        }

        return ['kind' => $type];
    }

    /**
     * decimal 类型读取：按指定位数四舍五入后返回 float。
     *
     * 返回数字类型（而非字符串），以便 JSON 序列化后前端数字输入组件
     * （如 Arco Design a-input-number）能直接回显。写入时 writeDecimal
     * 仍会格式化为指定位数的字符串写入数据库，不影响存储精度。
     *
     * 注意：底层使用 (float) 转换，超大位数（超过 PHP_INT_MAX 精度范围）
     * 的 decimal 如需精确运算，建议使用 type: 'string' 配合 bcmath 手动处理。
     */
    protected function readDecimal(mixed $value, int $scale): float
    {
        return round((float) $value, $scale);
    }

    /**
     * decimal 类型写入：转为指定位数的字符串写入数据库。
     */
    protected function writeDecimal(mixed $value, int $scale): string
    {
        if (is_string($value) || is_numeric($value)) {
            return number_format((float) $value, $scale, '.', '');
        }

        return (string) $value;
    }

    /**
     * date 类型读取：按指定格式解析为 DateTimeImmutable。
     */
    protected function readDate(mixed $value, string $format): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        $date = DateTimeImmutable::createFromFormat($format, (string) $value);

        return $date === false ? null : $date;
    }

    /**
     * date 类型写入：DateTimeInterface 按格式输出字符串。
     */
    protected function writeDate(mixed $value, string $format): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format($format);
        }

        return (string) $value;
    }

    /**
     * 枚举类型读取：将数据库值转为枚举实例。
     *
     * - 纯枚举（UnitEnum）按 name 匹配
     * - 回退枚举（BackedEnum）按 value 匹配
     */
    protected function readEnum(mixed $value, string $class): UnitEnum|string
    {
        $ref = new ReflectionEnum($class);

        if ($ref->isBacked()) {
            /** @var class-string<BackedEnum> $class */
            return $class::from($value);
        }

        foreach ($class::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        return (string) $value;
    }

    /**
     * 枚举类型写入：取出枚举的 value（回退枚举）或 name（纯枚举）。
     */
    protected function writeEnum(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return $value;
    }

    /**
     * 从数据库字段元信息推导字段类型。
     *
     * 支持自动推导 int / float / json 三种常见类型，
     * 避免手动配置 $type 和 $json。
     *
     * 推导规则：
     *   MySQL:
     *     - int/bigint/smallint/tinyint/mediumint 或 *int unsigned → 'int'
     *     - float/double/decimal → 'float'
     *     - json → 'json'
     *   SQLite:
     *     - INTEGER → 'int'
     *     - REAL/NUMERIC → 'float'
     *     - BLOB (当列名包含 json 标记) → 'json'
     */
    protected function inferFieldType(string $name): ?string
    {
        try {
            $table  = $this->getTable();
            $key    = $this->connection . ':' . $table . ':' . $name;
            $cached = self::$typeInferenceCache[$key] ?? null;

            if ($cached !== null) {
                return $cached; // 返回缓存值（可能为 'null'，表示无推导结果）
            }

            $fields = $this->getConnection()->getFields($table);
            if (!isset($fields[$name])) {
                self::$typeInferenceCache[$key] = 'null';

                return null;
            }

            $dbType = strtolower($fields[$name]['type'] ?? '');
            $mapped = self::mapDatabaseType($dbType);

            self::$typeInferenceCache[$key] = $mapped ?? 'null';

            return $mapped;
        } catch (Throwable) {
            // 获取表结构失败时静默跳过，保持原行为
            return null;
        }
    }

    /**
     * 将数据库字段类型映射到 ORM 标准类型。
     *
     * @param string $dbType 数据库返回的字段类型（如 'int(11)'、'decimal(10,2)'、'json' 等）
     * @return string|null ORM 标准类型（'int'/'float'/'json'）或 null
     */
    protected static function mapDatabaseType(string $dbType): ?string
    {
        // 去除末尾的注释和空格
        $dbType = trim($dbType);

        // 处理 MySQL 类型
        if (str_contains($dbType, 'int')) {
            // int / bigint / smallint / tinyint / mediumint / INT UNSIGNED / BIGINT UNSIGNED 等
            return 'int';
        }

        if (str_contains($dbType, 'float') || str_contains($dbType, 'double') || str_contains($dbType, 'decimal')) {
            return 'float';
        }

        if (str_contains($dbType, 'json')) {
            return 'json';
        }

        // 处理 SQLite 类型
        if ($dbType === 'integer' || $dbType === 'int') {
            return 'int';
        }

        if ($dbType === 'real' || str_contains($dbType, 'numeric')) {
            return 'float';
        }

        // TEXT 类型如果列名包含 json 相关标记（备选），这里不处理，
        // 因为 TEXT 通常用于普通字符串，json 应显式通过 $json 配置或数据库的 'json' 类型

        return null;
    }

    public function __get(string $name): mixed
    {
        // 关联缓存
        if (array_key_exists($name, $this->relation)) {
            return $this->relation[$name];
        }

        // 关联方法：lazy 加载（支持蛇形 / 驼峰关联名）
        $method = $this->resolveRelationMethod($name);
        if ($method !== null) {
            return $this->relation[$name] = $this->{$method}()->getResults();
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
        $this->modelConfig['hidden'] = array_values(array_unique(array_merge($this->modelConfig['hidden'], $fields)));

        return $this;
    }

    /**
     * @param array<int, string> $fields
     */
    public function visible(array $fields): static
    {
        $this->modelConfig['visible'] = array_values(array_unique(array_merge($this->modelConfig['visible'], $fields)));

        return $this;
    }

    /**
     * @param array<int, string>|string $attrs
     */
    public function append(array|string $attrs): static
    {
        $attrs                       = is_array($attrs) ? $attrs : [$attrs];
        $this->modelConfig['append'] = array_values(array_unique(array_merge(
            $this->modelConfig['append'] ?? [],
            $attrs,
        )));

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

        // 废弃字段过滤（仅作用于数据库属性，不影响 append/关联数据）
        if ($this->modelConfig['disuse'] !== []) {
            foreach ($this->modelConfig['disuse'] as $k) {
                unset($data[$k]);
            }
        }

        // append 虚拟字段 / 关联（#[Append] 属性声明 + 运行时 append() 调用）
        $hidden       = $this->modelConfig['hidden'];
        $appendFields = array_values(array_unique(array_merge(
            $this->getAppendMeta(),
            $this->modelConfig['append'] ?? [],
        )));

        foreach ($appendFields as $key) {
            // 跳过 hidden 字段，避免不必要的懒加载查询
            if (in_array($key, $hidden, true)) {
                continue;
            }
            if ($this->resolveRelationMethod($key) !== null) {
                // 关联：触发 lazy 加载（已预加载则直接取缓存）
                $data[$key] = $this->{$key};
            } else {
                $data[$key] = $this->getAttr($key);
            }
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

        // visible 优先于 hidden
        if ($this->modelConfig['visible'] !== []) {
            $data = array_intersect_key($data, array_flip($this->modelConfig['visible']));
        } elseif ($this->modelConfig['hidden'] !== []) {
            $data = array_diff_key($data, array_flip($this->modelConfig['hidden']));
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

        // 应用 #[Column] 声明的默认值（仅在字段未被显式赋值时）
        $this->applyColumnDefaults();

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
        foreach ($this->modelConfig['readonly'] as $k) {
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
     *
     * 若模型声明了 $cascade 关联列表，会在删除自身前依次删除关联记录，
     * 整个流程在一个事务内执行，失败自动回滚。
     */
    public function delete(): bool
    {
        if ($this->fireEvent('before_delete') === false) {
            return false;
        }

        $result = $this->getConnection()->transaction(function () {
            $this->cascadeDeleteRelations(false);

            if (method_exists($this, 'runSoftDelete')) {
                return (bool) $this->{'runSoftDelete'}();
            }

            $pk = $this->getKey();
            if ($pk === null) {
                return false;
            }

            $ok = $this->newQueryForWrite()->where($this->pk, $pk)->delete() > 0;
            if ($ok) {
                $this->exists = false;
            }

            return $ok;
        });

        if ($result) {
            $this->fireEvent('after_delete');
        }

        return $result;
    }

    /**
     * 强制删除（无论是否 SoftDelete 都真实删除）。
     *
     * 若模型声明了 $cascade 关联列表，会在删除自身前依次强制删除关联记录，
     * 整个流程在一个事务内执行，失败自动回滚。
     */
    public function forceDelete(): bool
    {
        $pk = $this->getKey();
        if ($pk === null) {
            return false;
        }

        $result = $this->getConnection()->transaction(function () use ($pk) {
            $this->cascadeDeleteRelations(true);

            $query = $this->newQueryForWrite();
            if (method_exists($this, 'getDeleteTimeField')) {
                $query->withTrashed();
            }

            return $query->where($this->pk, $pk)->delete() > 0;
        });

        if ($result) {
            $this->exists = false;
        }

        return $result;
    }

    /**
     * 按 $cascade 列表依次删除关联记录。
     *
     * @param bool $force true 时对子记录调用 forceDelete()，否则调用 delete()
     * @throws DbException 任一关联记录删除失败时抛出，以触发事务回滚
     */
    protected function cascadeDeleteRelations(bool $force): void
    {
        if ($this->cascade === []) {
            return;
        }

        foreach ($this->cascade as $relationName) {
            $method = $this->resolveRelationMethod((string) $relationName);
            if ($method === null) {
                continue;
            }

            $relation = $this->{$method}();
            if (!$relation instanceof Relation) {
                continue;
            }

            $related = $relation->getResults();

            if ($related instanceof Collection) {
                foreach ($related->all() as $item) {
                    if ($item instanceof Model) {
                        $ok = $force ? $item->forceDelete() : $item->delete();
                        if (!$ok) {
                            throw new DbException(sprintf(
                                'cascade delete aborted: relation "%s" record failed to delete',
                                $relationName,
                            ));
                        }
                    }
                }
            } elseif ($related instanceof Model) {
                $ok = $force ? $related->forceDelete() : $related->delete();
                if (!$ok) {
                    throw new DbException(sprintf(
                        'cascade delete aborted: relation "%s" record failed to delete',
                        $relationName,
                    ));
                }
            }
        }
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

        // 关联字段安全网：剔除前端误提交的嵌套关联数据，避免写入主表。
        // 关联列表由 getRelationNames() 反射自动获取（支持驼峰与蛇形）。
        $relationKeys = [];
        foreach ($this->getRelationNames() as $name) {
            $relationKeys[$name]               = true;
            $relationKeys[$this->snake($name)] = true;
        }

        $data = [];
        foreach ($allow as $field) {
            if (isset($relationKeys[$field])) {
                continue;
            }
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

    /**
     * 多对多关联。
     *
     *   $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
     *
     * @template TRelated of Model
     * @param  class-string<TRelated> $related
     * @param  string $table           中间表名（逻辑名，不含前缀），默认按双方表名字母序拼接
     * @param  string $foreignPivotKey 中间表中指向当前模型的外键字段，默认 {model}_id
     * @param  string $relatedPivotKey 中间表中指向目标模型的外键字段，默认 {related}_id
     * @param  string $parentKey       当前模型的本地键，默认主键
     * @param  string $relatedKey      目标模型的本地键，默认主键
     * @return BelongsToMany<TRelated>
     */
    protected function belongsToMany(
        string $related,
        string $table = '',
        string $foreignPivotKey = '',
        string $relatedPivotKey = '',
        string $parentKey = '',
        string $relatedKey = '',
    ): BelongsToMany {
        $parentKey  = $parentKey  !== '' ? $parentKey : $this->pk;
        $relatedKey = $relatedKey !== '' ? $relatedKey : (new $related())->getPk();

        $foreignPivotKey = $foreignPivotKey !== '' ? $foreignPivotKey : $this->defaultForeignKey();
        $relatedPivotKey = $relatedPivotKey !== '' ? $relatedPivotKey : $this->defaultForeignKey($related);

        if ($table === '') {
            $tables = [$this->getLogicalTableName(), (new $related())->getLogicalTableName()];
            sort($tables);
            $table = implode('_', $tables);
        }

        return new BelongsToMany($this, $related, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey);
    }

    /**
     * 获取模型的逻辑表名（不含表前缀、不含后缀）。
     *
     * 用于多对多中间表的默认命名推导等场景。
     */
    public function getLogicalTableName(): string
    {
        if ($this->table !== '') {
            return $this->table;
        }

        return $this->name !== '' ? $this->name : $this->inferTableName();
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

    /**
     * 驼峰转蛇形（adPlatform -> ad_platform）。
     */
    protected function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    /**
     * 判断字段是否定义了访问器（getXxxAttr）。
     */
    public function isAccessor(string $name): bool
    {
        // 优先检查 #[Getter] 显式绑定
        if (isset($this->getGetterMethods()[$name])) {
            return true;
        }

        return method_exists($this, 'get' . $this->studly($name) . 'Attr');
    }

    /**
     * 判断字段是否为关联方法（返回 Relation 实例）。
     *
     * 支持蛇形（ad_platform）与驼峰（adPlatform）两种关联名。
     */
    public function isRelation(string $name): bool
    {
        return $this->resolveRelationMethod($name) !== null;
    }

    /**
     * 把外部传入的关联名解析为实际的关联方法名（驼峰）。
     *
     * - 'ad_platform' -> 'adPlatform'
     * - 'adPlatform'  -> 'adPlatform'
     * - 非关联方法（数据字段、普通方法、访问器）返回 null。
     *
     * 数据字段优先：若 $name 已是模型属性，则不视为关联。
     */
    public function resolveRelationMethod(string $name): ?string
    {
        if ($this->hasAttr($name)) {
            return null;
        }

        // 1. 驼峰原名
        if (method_exists($this, $name) && $this->callReturnsRelation($name)) {
            return $name;
        }

        // 2. 蛇形 -> 驼峰（ad_platform -> adPlatform）
        $camel = lcfirst($this->studly($name));
        if ($camel !== $name && method_exists($this, $camel) && $this->callReturnsRelation($camel)) {
            return $camel;
        }

        return null;
    }

    /**
     * 调用指定方法并判断是否返回 Relation 实例。
     */
    protected function callReturnsRelation(string $method): bool
    {
        try {
            return $this->{$method}() instanceof Relation;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 获取模型声明的所有关联方法名。
     *
     * 通过反射扫描当前模型（含父类，不含 Model 基类自身）的 public 方法，
     * 筛选返回值类型为 {@see Relation} 或其子类（HasOne / HasMany / BelongsTo / BelongsToMany）的方法。
     *
     * 与 {@see callReturnsRelation()} 不同，本方法不实际调用关联方法，
     * 仅依据声明的返回类型判断，无副作用。
     *
     * 结果按类缓存，避免重复反射开销。
     *
     * @return array<int, string> 关联方法名列表（驼峰，如 orders、adPlatform）
     */
    public function getRelationNames(): array
    {
        $class = static::class;

        if (!isset(self::$relationNamesCache[$class])) {
            $relationNames = [];
            $ref           = new ReflectionClass($class);

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                // 跳过 Model 基类自身声明的方法（hasOne/hasMany/belongsTo 为 protected，
                // 不会出现在 IS_PUBLIC 结果中；此处仅作保险）
                if ($method->getDeclaringClass()->getName() === self::class) {
                    continue;
                }

                $returnType = $method->getReturnType();
                if (!$returnType instanceof ReflectionNamedType) {
                    continue;
                }

                $typeName = $returnType->getName();
                if ($typeName === Relation::class || is_subclass_of($typeName, Relation::class)) {
                    $relationNames[] = $method->getName();
                }
            }

            self::$relationNamesCache[$class] = $relationNames;
        }

        return self::$relationNamesCache[$class];
    }

    /**
     * 从输入数据中剔除关联字段（支持蛇形与驼峰命名）。
     *
     * 关联数据不应写入主表。前端提交的嵌套关联数据需在保存前剔除，
     * 关联列表由 {@see getRelationNames()} 反射自动获取，无需手动维护。
     *
     * 用法：
     *   $data = $model->filterRelationFields($request->post());
     *   $model->save($data);
     *
     * @param array<string, mixed> $data 原始输入数据
     * @return array<string, mixed> 剔除关联字段后的数据
     */
    public function filterRelationFields(array $data): array
    {
        $relationNames = $this->getRelationNames();
        if ($relationNames === []) {
            return $data;
        }

        // 同时匹配驼峰（adPlatform）与蛇形（ad_platform）键名
        $relationKeys = [];
        foreach ($relationNames as $name) {
            $relationKeys[$name]               = true;
            $relationKeys[$this->snake($name)] = true;
        }

        return array_diff_key($data, $relationKeys);
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
