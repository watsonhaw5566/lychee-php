# 数据库 ORM

框架内置轻量级 ORM（命名空间 `Lychee\orm`），提供常用链式查询 API，专注于 IDE 友好与可读的实现。底层基于 PDO 直连 + 预编译语句，提供查询构造器、Active Record 模型、关联预加载、软删除、事务、断线重连、SQL 日志等能力。

> 仅实现常用功能子集；若需要更复杂的能力（视图模型、关联统计、事件订阅器等），请评估是否可用现有 API 组合实现。

## 配置

在 `config/database.php` 中定义连接，`default` 指定默认连接名：

```php
return [
    // 默认连接
    'default' => env('DB_DRIVER', 'mysql'),

    // 连接列表
    'connections' => [
        'mysql' => [
            'type'            => env('DB_TYPE', 'mysql'),
            'hostname'        => env('DB_HOST', '127.0.0.1'),
            'hostport'        => env('DB_PORT', '3306'),
            'database'        => env('DB_NAME', ''),
            'username'        => env('DB_USER', 'root'),
            'password'        => env('DB_PASS', ''),
            'charset'         => env('DB_CHARSET', 'utf8mb4'),
            'prefix'          => env('DB_PREFIX', ''),
            'debug'           => false,

            // 是否开启断线重连（默认 false）
            // 常驻进程（队列/定时任务/WebSocket）中建议开启，
            // 查询遇到 "Lost connection"、"MySQL server has gone away" 等断线异常时自动重连重试
            'break_reconnect' => false,

            // 是否监听 SQL（默认 true）
            // 开启后执行的 SQL 会通过框架日志模块输出
            // 日志频道优先使用 config/log.php 中的 'sql' 频道，未配置则使用默认频道
            'trigger_sql'     => env('APP_DEBUG', true),

            // 是否严格检查字段（默认 true）
            // true：写入/更新时若包含数据表不存在的字段会抛出异常
            // false：忽略不存在的字段
            'fields_strict'   => true,
        ],
    ],

    // 以下为全局配置（对所有模型生效）

    // 是否自动写入时间戳（默认 true）
    'auto_timestamp'  => true,

    // 时间字段读取格式
    'datetime_format' => 'Y-m-d H:i:s',

    // 全局时间字段名，格式：create_time,update_time
    'datetime_field'  => '',
];
```

框架启动时自动读取该配置并初始化 `Lychee\orm\ConnectionManager`，同时注入到 `Lychee\orm\Model` 基类与 `Lychee\orm\facade\Db` 门面。

### 配置说明

| 配置项 | 位置 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `break_reconnect` | connections 内 | `false` | 断线重连开关，常驻进程建议开启 |
| `trigger_sql` | connections 内 | `true` | SQL 监听开关，开启后 SQL 输出到日志 |
| `fields_strict` | connections 内 | `true` | 字段严格检查（由 Model 的字段白名单自动约束） |
| `auto_timestamp` | 顶层 | `true` | 模型自动写入创建/更新时间 |
| `datetime_format` | 顶层 | `Y-m-d H:i:s` | 时间字段读取格式 |
| `datetime_field` | 顶层 | `''` | 全局时间字段名，格式 `create_time,update_time` |

## 获取数据库实例

两种方式：

```php
use Lychee\orm\facade\Db;

// 方式一：门面（推荐，IDE 友好）
Db::table('users')->select();

// 方式二：容器解析
/** @var \Lychee\orm\ConnectionManager $manager */
$manager = app('db');
$manager->connect()->select('SELECT * FROM users');
```

## 查询构造器

通过 `Db::table()` / `Db::name()` 获取查询构造器，支持链式调用。`table()` 使用完整表名，`name()` 自动拼接连接的 `prefix`。

### 查询

```php
use Lychee\orm\facade\Db;

// 查询全部
$users = Db::table('users')->select();

// 查询单条（按主键）
$user = Db::table('users')->find(1);

// 条件查询
$list = Db::table('users')
    ->where('status', 1)
    ->where('age', '>', 18)
    ->order('id', 'desc')
    ->limit(10)
    ->select();

// where 操作符自动识别：值为数组时自动路由到 IN / BETWEEN
Db::table('users')->where('id', 'in', [1, 2, 3])->select();          // 等价 whereIn
Db::table('users')->where('id', 'not in', [1, 2])->select();
Db::table('users')->where('age', 'between', [18, 30])->select();     // 等价 whereBetween
Db::table('users')->where('age', 'not between', [18, 30])->select();
Db::table('users')->whereOr('id', 'in', [1, 2])->select();           // OR 同样生效

// 模糊查询
Db::table('users')->whereLike('name', '%张%')->select();

// IN / NOT IN
Db::table('users')->whereIn('id', [1, 2, 3])->select();
Db::table('users')->whereNotIn('id', [1, 2])->select();

// 区间查询
Db::table('users')->whereBetween('age', 18, 30)->select();
Db::table('orders')->whereBetweenTime('create_time', '2024-01-01', '2024-01-31')->select();

// 自然时间区间（today / yesterday / week / month / year）
Db::table('orders')->whereTime('create_time', 'today')->select();
Db::table('orders')->whereTime('create_time', 'week')->select();
Db::table('orders')->whereTime('create_time', '2024-01-01~2024-01-31')->select();

// 字段间比较
Db::table('orders')->whereColumn('end_time', '>', 'start_time')->select();
Db::table('orders')->whereColumn('left', 'right')->select();           // 默认 =

// NULL 判断
Db::table('users')->whereNull('deleted_at')->select();
Db::table('users')->whereNotNull('email')->select();

// EXISTS / NOT EXISTS
Db::table('users')->whereExists(fn ($q) => $q->table('orders')->whereRaw('orders.user_id = users.id'))->select();
Db::table('users')->whereNotExists(fn ($q) => $q->table('orders')->whereRaw('orders.user_id = users.id'))->select();

// 原生片段
Db::table('users')->whereRaw('score > ? AND status = ?', [60, 1])->select();

// 嵌套 OR
Db::table('users')->where(function ($q) {
    $q->where('role', 'admin')->whereOr('role', 'manager');
})->select();

// 条件子句 when：避免 if 打断链式调用
Db::table('users')
    ->when($keyword, fn ($q) => $q->whereLike('name', "%{$keyword}%"))
    ->when($status !== null, fn ($q) => $q->where('status', $status))
    ->select();

// 聚合
$count = Db::table('users')->where('status', 1)->count();
$sum   = Db::table('orders')->sum('amount');
$avg   = Db::table('users')->avg('age');
$max   = Db::table('users')->max('age');

// 指定字段
Db::table('users')->field('id,name')->select();
Db::table('users')->field(['id', 'name'])->select();

// 分页（offset + limit）
Db::table('users')->page(2, 20)->select();

// page 单参数重载：仅传每页条数，当前页码自动从 Request 读取（?page= / ?current=）
Db::table('users')->page(20)->select();

// 一步分页（返回 Paginator 对象，适合 service 层二次加工）
$paginator = Db::table('users')->where('status', 1)->paginate(2, 20);

// Paginator 通过语义化方法访问数据
$paginator->items();          // 当前页数据数组（自动 ->all()，省去每次手写）
$paginator->total();          // 100
$paginator->perPage();        // 20
$paginator->currentPage();    // 2
$paginator->lastPage();       // 5
$paginator->isEmpty();        // false
$paginator->list();           // 原始 list（Collection 或数组）

// 也支持数组访问（ArrayAccess，兼容 ThinkPHP 写法）
$paginator['total'];        // 100
$paginator['list'];         // 当前页数据
$paginator['current'];      // 2
$paginator['pageSize'];     // 20
$paginator['current_page']; // 2（下划线风格）
$paginator['per_page'];     // 20
$paginator['last_page'];    // 5

// 序列化输出同时包含驼峰（current/pageSize）与下划线（current_page/per_page）两套键名
$paginator->toArray();
json_encode($paginator);

// 简单分页（不统计 total，性能更优）
Db::table('users')->paginate(2, 20, simple: true);

// 分批处理（避免一次性加载大量数据）
Db::table('orders')->where('status', 1)->chunk(500, function ($orders) {
    foreach ($orders as $order) {
        // ...
    }
});

// 按主键游标分批（比 chunk 更高效，避免深分页 offset）
Db::table('orders')->where('status', 1)->chunkById(500, function ($orders) {
    foreach ($orders as $order) {
        // ...
    }
});

// 流式查询（PDO 逐行 yield，内存友好）
foreach (Db::table('orders')->where('status', 1)->cursor() as $order) {
    // ...
}

// 单字段取值
$name  = Db::table('users')->where('id', 1)->value('name');
$names = Db::table('users')->where('status', 1)->column('name');
$map   = Db::table('users')->where('status', 1)->column('name', 'id'); // [id => name]

// 多字段取值
$rows = Db::table('users')->column('id,name,email');              // [['id'=>..,'name'=>..,'email'=>..], ...]
$map  = Db::table('users')->column('name,email', 'id');           // [id => ['name'=>..,'email'=>..]]

// JOIN
Db::table('users')
    ->alias('u')
    ->leftJoin('profiles p', 'p.user_id = u.id')
    ->field('u.*,p.avatar')
    ->select();

// 悲观锁
Db::table('users')->where('id', 1)->lock()->find();           // FOR UPDATE
Db::table('users')->where('id', 1)->lock('shared')->find();   // LOCK IN SHARE MODE
```

### 插入

```php
// 插入单条
Db::table('users')->insert([
    'name'  => 'Tom',
    'email' => 'tom@example.com',
    'age'   => 20,
]);

// 插入并返回自增 ID
$id = Db::table('users')->insertGetId([
    'name' => 'Jerry',
]);

// 批量插入
Db::table('users')->insertAll([
    ['name' => 'A', 'age' => 1],
    ['name' => 'B', 'age' => 2],
]);
```

### 更新

```php
Db::table('users')
    ->where('id', 1)
    ->update(['name' => 'New Name', 'age' => 25]);

// 字段自增 / 自减
Db::table('users')->where('id', 1)->inc('score', 5);
Db::table('users')->where('id', 1)->dec('score', 2);
```

### 删除

```php
Db::table('users')->where('id', 1)->delete();
```

> 若通过模型查询且模型启用了 SoftDelete，`Query::delete()` 默认执行**软删除**（写入 delete_time），
> 与 `$model->delete()` 行为一致，避免误用导致数据丢失。需要真正删除时使用 `forceDelete()`：
>
> ```php
> Post::where('status', 0)->delete();      // 软删除
> Post::where('status', 0)->forceDelete(); // 硬删除
> ```

### 原生查询

```php
// 读
Db::query('SELECT * FROM users WHERE status = ?', [1]);

// 写
Db::execute('UPDATE users SET status = 0 WHERE id = ?', [1]);

// 原生片段（可嵌入 where / update / order）
use Lychee\orm\facade\Db;

Db::table('users')->order(Db::raw('FIELD(id, ?, ?, ?)', [3, 1, 2]))->select();
Db::table('users')->update(['view_count' => Db::raw('view_count + 1')]);
```

## 集合 Collection

`Model::select()` 返回 `Lychee\orm\Collection`，封装一组模型实例，提供常用集合操作。集合实现了 `ArrayAccess` / `IteratorAggregate` / `Countable`，可直接当数组使用。

```php
use app\model\User;

$users = User::where('status', 1)->select();

// 基础
$users->count();
$users->isEmpty();
$users->first();
$users->last();

// 取字段
$names = $users->column('name');           // ['Tom', 'Jerry', ...]
$map   = $users->column('name', 'id');     // [1 => 'Tom', 2 => 'Jerry', ...]
$names = $users->pluck('name');            // column 的别名

// 重新索引 / 分组
$users->keyBy('id');                       // Collection，键为 id
$users->groupBy('role');                   // [role => Collection]

// 遍历与变换
$users->each(fn ($u) => $u->doSomething());
$mapped = $users->map(fn ($u) => $u->name);
$filtered = $users->filter(fn ($u) => $u->age > 18);

// 归并
$total = $users->reduce(fn ($carry, $u) => $carry + $u->age, 0);

// 聚合
$total = $users->sum('age');
$avg   = $users->avg('age');
$min   = $users->min('age');
$max   = $users->max('age');

// 判断包含
$users->contains('name', 'Tom');           // 是否存在 name=Tom 的项
$users->contains(fn ($u) => $u->age > 60); // 回调判断

// 排序
$sorted = $users->sortBy('age');           // 升序，保持键
$sorted = $users->sortBy('age', SORT_NUMERIC);

// 去重 / 扁平化
$unique = $users->unique('role');
$flat   = $nested->flatten();              // 项为数组时扁平化一层
$flat   = $collections->collapse();        // flatten 的别名

// 批量删除
$users->delete();

// 条件过滤（集合内按字段筛选，返回新的 Collection）
$adults    = $users->where('age', 18);
$inGroup   = $users->whereIn('id', [1, 2, 3]);
$notInGroup = $users->whereNotIn('id', [4, 5]);

// 集合运算（模型按主键比较，数组按值比较）
$diff      = $users->diff($otherUsers);      // 差集
$intersect = $users->intersect($otherUsers); // 交集

// 按键筛选
$only = $users->only([0, 1]);      // 仅保留键为 0、1 的项
$except = $users->except([0]);     // 排除键为 0 的项

// 集合内分页（对已加载到内存的集合做客户端分页，返回 Paginator）
$paginator = $users->paginate(2, 10);

// 转数组 / JSON
$users->toArray();
$users->toJson();
```

## 模型

模型继承 `Lychee\orm\Model`，把表操作封装为对象。模型会自动绑定全局 `ConnectionManager`，无需手动注入连接。

### 定义模型

```php
// app/model/User.php
namespace app\model;

use Lychee\orm\Model;

/**
 * @property int    $id
 * @property string $name
 * @property string $email
 * @property int    $age
 */
class User extends Model
{
    // 不含前缀的表名（将自动拼接连接的 prefix）；
    // 留空（默认 ''）时自动从类名推断（User -> user，UserOrder -> user_order）
    protected string $name = 'users';

    // 或直接指定完整表名
    // protected string $table = 'pre_users';

    // 主键字段，默认 id
    // protected string $pk = 'id';

    // 字段白名单；为空时自动从数据库表结构读取
    // protected array $field = ['id', 'name', 'email', 'age'];

    // 废弃字段（见下方“废弃字段”章节）
    // protected array $disuse = [];
}
```

> **表名自动推断**：`$name` 为空字符串 `''`（默认值）时，框架从类名推断表名（去命名空间、驼峰转下划线）。
> 例如 `app\model\UserOrder` → `user_order`。需要显式指定时声明 `protected string $name = 'xxx'`。
>
> **字段名冲突防护**：`hidden` / `visible` / `append` / `json` / `readonly` / `disuse` / `type` / `field`
> 等配置统一收敛到 `$modelConfig` 数组，不再占用模型属性名空间。因此即使数据库存在同名字段
> （如 `hidden`、`type`），`$model->hidden = 1` 也会正确写入数据而非触发类型错误。
> 子类仍可通过 `protected array $hidden = [...]` 声明配置（构造时自动合并到 `$modelConfig`）。

### 模型 CRUD

```php
use app\model\User;

// 查询
$user = User::find(1);                          // 按主键
$user = User::findOrFail(1);                    // 查不到抛 ModelNotFoundException
$list = User::where('status', 1)->select();     // Collection<User>
$all  = User::select();

// 新增（静态 create）
$user = User::create([
    'name'  => 'Tom',
    'email' => 'tom@example.com',
    'age'   => 20,
]);
echo $user->id; // 自增 ID 已回填

// 新增（实例 save）
$user        = new User();
$user->name  = 'Jerry';
$user->email = 'jerry@example.com';
$user->save();

// 更新
$user       = User::find(1);
$user->name = 'New Name';
$user->save();

// 批量更新
User::where('status', 1)->update(['status' => 0]);

// 删除
$user = User::find(1);
$user->delete();

// 条件删除
User::where('id', 1)->delete();

// 按主键批量删除（返回成功删除条数）
User::destroy([1, 2, 3]);
User::destroy(1);                       // 单个主键

// 按条件删除（关联数组视为 where 条件）
User::destroy(['status' => 0]);

// 强制硬删除（忽略软删除，第二参数传 true）
User::destroy([1, 2, 3], true);
User::destroy(['status' => 0], true);

// 启用 SoftDelete 的模型，destroy() 默认走软删除

// 批量保存模型实例（自动区分新增 / 更新，在事务内执行）
$existing = User::find(1);
$existing->name = 'b';
$users = [
    new User(['name' => 'a']),
    $existing,
];
User::saveAll($users);

// 字段自增 / 自减（实例方法，执行后同步刷新内存值）
$user = User::find(1);
$user->increment('score', 5);   // score += 5
$user->decrement('score', 2);   // score -= 2

// 重新加载
$fresh = $user->fresh();        // 返回新实例，不修改自身
$user->refresh();               // 刷新自身数据与 origin
```

控制器中可通过依赖注入获取模型实例：

```php
use app\model\User;

class UserController
{
    public function __construct(
        private readonly User $user,
    ) {}

    public function index()
    {
        return $this->user->where('status', 1)->select();
    }
}
```

### 查询范围（Scope）

框架**不**采用 `scopeXxx` 魔术方法（会导致 IDE 无法补全），推荐直接在模型中定义**真实的 `static` 方法**返回 `Query`。IDE 可完整支持补全、跳转与重构。

```php
class User extends Model
{
    // 仅活跃用户
    public static function active(): Query
    {
        return static::query()->where('status', 1);
    }

    // 成年用户
    public static function adult(): Query
    {
        return static::query()->where('age', '>=', 18);
    }
}

// 调用（IDE 可补全 active / adult）
User::active()->select();
User::adult()->order('id', 'desc')->select();
```

需要从已有查询上继续叠加条件时，让方法接受可选的 `Query` 参数：

```php
public static function active(?Query $query = null): Query
{
    return ($query ?? static::query())->where('status', 1);
}

// 从已有查询继续
User::active(User::where('age', '>', 18))->select();
```

### 字段类型转换

```php
class User extends Model
{
    // 类型映射：int/float/bool/string/array/object/json/datetime/timestamp
    protected array $type = [
        'id'      => 'int',
        'balance' => 'float',
        'is_vip'  => 'bool',
        'extra'   => 'json',
    ];

    // 等价于 type 中设 'json'，可简化写法
    protected array $json = ['extra'];

    // JSON 解码时是否关联数组（默认 false）
    protected bool $jsonAssoc = false;
}
```

### 废弃字段 `$disuse`

当数据表存在历史遗留的废弃字段，可在模型里声明让 ORM 在查询与写入时自动忽略：

```php
class User extends Model
{
    // 这些字段在数据库里仍存在，但不再使用
    protected array $disuse = ['status', 'type'];
}
```

效果：

- `SELECT` 自动采用模型字段白名单（扣除 `$disuse`），不会把废弃字段读出
- `save()` / `update()` 时会跳过 `$disuse` 中的字段，哪怕实例上被赋值也不会写入
- `toArray()` / JSON 序列化输出中会过滤掉废弃字段

### 只读字段 `$readonly`

```php
class User extends Model
{
    // 仅在 INSERT 时写入，UPDATE 时自动忽略
    protected array $readonly = ['email'];
}
```

### 隐藏 / 可见 / 追加字段

```php
class User extends Model
{
    // toArray / JSON 序列化时排除
    protected array $hidden = ['password'];

    // 白名单（优先级高于 hidden）
    protected array $visible = [];

    // 虚拟字段，调用 getXxxAttr 作为输出
    protected array $append = ['full_name'];

    public function getFullNameAttr($value, array $data): string
    {
        return $data['first_name'] . ' ' . $data['last_name'];
    }
}

// 查询时临时调整
User::where('status', 1)
    ->hidden(['password'])
    ->append(['full_name'])
    ->visible(['id', 'name'])
    ->select();
```

### 获取器 / 修改器

```php
class User extends Model
{
    // 获取器：读取时自动处理
    public function getNameAttr($value): string
    {
        return ucfirst((string) $value);
    }

    // 修改器：写入时自动处理
    public function setPasswordAttr($value): string
    {
        return password_hash((string) $value, PASSWORD_DEFAULT);
    }
}
```

### 时间字段

```php
class User extends Model
{
    // 'datetime' 写入 Y-m-d H:i:s；'timestamp' 写入时间戳；false 关闭
    protected bool|string $autoWriteTimestamp = true;

    protected string $createTime = 'create_time';
    protected string $updateTime = 'update_time';
    protected string $dateFormat = 'Y-m-d H:i:s';
}
```

也可通过 `database.datetime_field` 全局修改所有模型的时间字段名，格式 `create_time,update_time`。

### 关联

支持 `hasOne` / `hasMany` / `belongsTo` 三种关联。

```php
class User extends Model
{
    // 一对一
    public function profile(): \Lychee\orm\relation\HasOne
    {
        return $this->hasOne(Profile::class, 'user_id');
    }

    // 一对多
    public function posts(): \Lychee\orm\relation\HasMany
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}

class Post extends Model
{
    // 反向属于
    public function author(): \Lychee\orm\relation\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

// 预加载（避免 N+1）
$users = User::with(['profile', 'posts'])->select();

// 懒加载：首次访问时查询
$user = User::find(1);
$user->profile; // 自动触发查询
$user->posts;
```

### 软删除

模型 `use` `Lychee\orm\concern\SoftDelete` trait 即启用：

```php
use Lychee\orm\Model;
use Lychee\orm\concern\SoftDelete;

class Post extends Model
{
    use SoftDelete;

    // 删除时写入的字段，默认 delete_time
    protected string $deleteTime = 'delete_time';
}

// delete() 变为逻辑删除
Post::find(1)->delete();

// 全局自动过滤已删除记录
Post::select();                       // 不含已删除
Post::withTrashed()->select();        // 包含已删除
Post::onlyTrashed()->select();        // 仅已删除

// 恢复
$post = Post::withTrashed()->find(1);
$post->restore();

// 强制删除
Post::find(1)->forceDelete();
```

**关联中加载已软删除的记录**：在关联定义上链式调用 `withTrashed()`，可加载被软删除的关联数据
（例如列表中展示"活动已删除"但仍需关联数据）。

```php
class Order extends Model
{
    public function activity(): BelongsTo
    {
        // 包含已软删除的活动
        return $this->belongsTo(Activity::class, 'activity_id', 'id')->withTrashed();
    }
}
```

## 事务

```php
use Lychee\orm\facade\Db;

// 自动事务（闭包抛出异常自动回滚）
Db::transaction(function () {
    Db::table('users')->where('id', 1)->dec('balance', 100);
    Db::table('orders')->insert(['user_id' => 1, 'amount' => 100]);
});

// 模型级事务（在该模型所属连接上执行）
use app\model\Order;

Order::transaction(function () {
    Order::where('id', 1)->update(['status' => 1]);
    // ...
});
```

手动控制：

```php
Db::startTrans();
try {
    Db::table('users')->where('id', 1)->dec('balance', 100);
    Db::table('orders')->insert(['user_id' => 1, 'amount' => 100]);

    Db::commit();
} catch (\Throwable $e) {
    Db::rollback();
    throw $e;
}
```

嵌套事务通过 `SAVEPOINT` 实现，内层回滚不会影响外层。

## 模型事件

模型在新增、更新、删除前后会自动触发对应事件。推荐在模型内用**静态方法**声明回调，也可以通过 `Model::event()` 注册**闭包**。

### 支持的事件

| 事件 | 对应静态方法 | 触发时机 |
| --- | --- | --- |
| `before_write` | `onBeforeWrite` | `save()` 前（新增与更新都触发） |
| `after_write` | `onAfterWrite` | `save()` 成功后 |
| `before_insert` | `onBeforeInsert` | 新增前 |
| `after_insert` | `onAfterInsert` | 新增成功后 |
| `before_update` | `onBeforeUpdate` | 更新前 |
| `after_update` | `onAfterUpdate` | 更新成功后 |
| `before_delete` | `onBeforeDelete` | 删除前（含软删除） |
| `after_delete` | `onAfterDelete` | 删除成功后 |

> **拦截流程**：任意 `before_*` 回调返回 `false` 都会中断本次 `save()` / `delete()`，并让其返回 `false`。`after_*` 回调的返回值被忽略。

### 方式一：静态方法（推荐）

直接在模型类内声明 `on{StudlyEvent}` 静态方法，参数为当前模型实例：

```php
<?php
namespace app\model;

use Lychee\orm\Model;

class User extends Model
{
    // 新增前补默认值
    public static function onBeforeInsert(User $user): void
    {
        $user->uuid ??= uuid();
    }

    // 更新前拦截
    public static function onBeforeUpdate(User $user): bool|null
    {
        if ($user->name === 'forbidden') {
            return false; // 阻断本次更新
        }
        return null;
    }

    // 删除后级联清理
    public static function onAfterDelete(User $user): void
    {
        Profile::destroy($user->id);
    }
}
```

事件名到方法名的映射规则：下划线转 StudlyCase 并加 `on` 前缀，例如 `before_insert` → `onBeforeInsert`。方法必须声明为 `public static`，且**无需继承任何额外接口或 trait**，框架会自动检测并调用。

### 方式二：闭包注册

适合在服务启动阶段（如 `Bootstrap` / `AppServiceProvider`）集中挂载事件：

```php
use app\model\User;

User::event('before_insert', function (User $user) {
    $user->uuid ??= uuid();
});

User::event('before_update', function (User $user) {
    if ($user->isReadonlyAccount()) {
        return false; // 阻断更新
    }
});
```

### 调用顺序

对同一事件，框架先调用模型里的静态方法，再按注册顺序调用通过 `event()` 添加的闭包；任一回调返回 `false` 都会立刻终止后续回调。两种方式可以并存，便于把"模型内固有逻辑"和"运行期动态逻辑"分离维护。

## 切换连接

```php
Db::connect('sqlite')->select('SELECT 1');
```

模型可通过 `protected string $connection` 指定使用的连接名。

## SQL 日志

当日志模块已加载且连接的 `trigger_sql=true` 时，所有执行过的 SQL 会输出到日志。
日志通道优先使用 `config/log.php` 中的 `sql` 频道，未配置则落到默认频道。

## 异常

| 异常类 | 场景 |
| --- | --- |
| `Lychee\orm\exception\DbException` | 通用数据库异常（配置错误、SQL 异常等） |
| `Lychee\orm\exception\DataNotFoundException` | `select()->failException()` 结果为空 |
| `Lychee\orm\exception\ModelNotFoundException` | `findOrFail()` 查不到记录 |
