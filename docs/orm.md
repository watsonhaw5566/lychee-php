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

// 判断是否存在匹配记录（比 count() > 0 更高效，命中首行即返回）
Db::table('users')->where('status', 1)->exists();       // true / false

// 指定字段
Db::table('users')->field('id,name')->select();
Db::table('users')->field(['id', 'name'])->select();

// 条数与偏移量
Db::table('users')->limit(10)->select();                              // 取前 10 条
Db::table('users')->limit(10, 5)->select();                           // 跳过 5 条、取 10 条（条数在前）

// 注意：limit() 参数顺序与 ThinkPHP 相反
//   Lychee:   limit($limit, $offset)  条数在前，偏移量在后
//   ThinkPHP: limit($offset, $length) 偏移量在前，条数在后

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
        return static::where('status', 1);
    }

    // 成年用户
    public static function adult(): Query
    {
        return static::where('age', '>=', 18);
    }
}

// 调用（IDE 可补全 active / adult）
User::active()->select();
User::adult()->order('id', 'desc')->select();
```

> `static::where(...)` 通过 `__callStatic` 自动转发到查询构造器，等价于 `static::query()->where(...)`，写法更简洁。
> Model 类的 `@method static` 注解保证 IDE 可补全 `where` / `order` / `find` 等查询方法。

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

#### 使用 `#[Column]` 属性（推荐）

在模型属性上标注 `#[Column]` 声明字段类型。支持参数化类型与枚举类：

```php
use Lychee\orm\attribute\Column;

class User extends Model
{
    // 基础类型
    #[Column(type: 'int')]
    protected int $id;

    // 精度小数：读取为 float（JSON 输出数字，兼容前端数字输入组件）
    #[Column(type: 'decimal:2')]
    protected float $balance;          // 读为 99.5

    // 日期格式：读取为 DateTimeImmutable
    #[Column(type: 'date:Y-m-d')]
    protected ?\DateTimeImmutable $birthday;

    // 枚举类：读取为枚举实例
    #[Column(type: UserStatus::class)]
    protected UserStatus $status;

    // JSON 字段（快捷方式，等价 type: 'json'）
    #[Column(json: true)]
    protected array $extra;
}
```

支持的 `type` 值：

| type 值 | 读取行为 | 写入行为 |
| --- | --- | --- |
| `int` / `integer` | `(int)` | `(int)` |
| `float` / `double` | `(float)` | `(float)` |
| `bool` / `boolean` | `(bool)` | `1` / `0` |
| `string` | `(string)` | `(string)` |
| `array` / `object` / `json` | `json_decode` | `json_encode` |
| `timestamp` | 时间戳格式化为 `dateFormat` | 转为时间戳 |
| `datetime` | 原样 | 原样 |
| `decimal:{scale}` | 四舍五入到指定位数的 `float`（如 `99.5`） | 格式化为指定位数字符串写入数据库 |
| `date:{format}` | 解析为 `DateTimeImmutable` | 按格式输出字符串 |
| `EnumClass::class` | 转为枚举实例（BackedEnum 按 value，UnitEnum 按 name） | 取 `->value` 或 `->name` |

> **类型解析优先级**：`#[Column]` > 数据库表结构自动推导 > 约定通用字段（id / create_time 等）。

#### 默认值

通过 `default` 参数声明 INSERT 时的默认值。**仅在字段未被显式赋值时生效**，显式赋值（包括赋 `null`）不会被覆盖。默认值会经过 `writeTransform` 转换，因此与手动赋值行为一致：

```php
use Lychee\orm\attribute\Column;

class User extends Model
{
    // 枚举默认值：写入时取 ->value（'active'）
    #[Column(type: UserStatus::class, default: UserStatus::Active)]
    protected UserStatus $status;

    // 精度小数默认值：写入 0.00（经 writeDecimal 格式化为字符串入库）
    #[Column(type: 'decimal:2', default: 0.00)]
    protected float $balance;

    // JSON 默认值：写入时 json_encode 为 '[]'
    #[Column(json: true, default: [])]
    protected array $tags;

    // 整数默认值
    #[Column(type: 'int', default: 0)]
    protected int $score;
}
```

> `default: null` 视为未声明默认值，不做任何处理。默认值仅在 `INSERT`（`save()` 新增）时应用，`UPDATE` 不会填充默认值。

#### 类型推导优先级

ORM 采用三层优先级自动推导字段类型，**无需手动配置大多数常见字段**：

1. **`#[Column]` 属性** - 显式声明的类型最高优先
2. **数据库表结构自动推导** - 从 DB 元信息推导字段类型
3. **约定通用字段** - `id` / `create_time` / `update_time` / `delete_time` 等常用字段有默认类型

因此可以大幅简化模型配置，直接删除自动推导支持的字段声明：

```php
class Order extends Model
{
    // int/bigint 字段自动推导为 int，decimal/float 自动推导，json 字段自动推导
    // 无需任何 $type 配置

    // 仅保留需要显式指定的字段（如自定义序列化需求）
    #[Column(type: 'datetime')]
    protected ?\DateTimeImmutable $created_at;
}
```

#### 自动推导支持的类型

| 数据库类型 | ORM 标准类型 | 说明 |
| --- | --- | --- |
| `INT` / `INTEGER` / `BIGINT` / `SMALLINT` | `int` | 整数类型（MySQL int/bigint/smallint；SQLite INTEGER） |
| `DECIMAL` / `FLOAT` / `DOUBLE` / `REAL` | `float` | 浮点类型（MySQL decimal/float/double；SQLite REAL） |
| `JSON` | `json` | JSON 类型（MySQL json；SQLite 存储为 TEXT 但声明为 JSON） |

#### 缓存机制

字段类型推导结果会被缓存，避免重复查询数据库表结构。缓存键格式为 `connection:table:field`，存储在静态属性 `$typeInferenceCache` 中，整个应用生命周期有效。

#### 多数据库支持

自动推导支持 **MySQL** 和 **SQLite** 两种数据库：

- **MySQL**：识别 `int(11)` / `decimal(10,2)` 等带参数的类型
- **SQLite**：识别 `INTEGER` / `REAL` 等标准类型

#### 手动配置场景

仍需显式使用 `#[Column]` 的场景：

- 时间戳字段需要自定义序列化格式（如 `date:Y-m-d` / `datetime`）
- 某个整数字段不需要自动类型转换，保持字符串形式
- 自定义类型转换逻辑（覆盖 `readTransform()` / `writeTransform()` 方法）

### JSON 字段

JSON 字段的读写会自动进行 `json_encode` / `json_decode` 转换，无需手动处理。

#### 声明方式

`#[Column]` 是属性级别的 Attribute，多个 JSON 字段各自声明即可，没有数量限制：

```php
use Lychee\orm\attribute\Column;

class User extends Model
{
    // 写法一：json 快捷参数（等价 type: 'json'）
    #[Column(json: true)]
    protected array $tags;

    // 写法二：显式 type
    #[Column(type: 'json')]
    protected ?array $permissions;

    // 带默认值：INSERT 时若未赋值，自动 json_encode 为 '[]'
    #[Column(json: true, default: [])]
    protected array $settings;
}
```

两种写法完全等价，`getJsonFields()` 都会识别。

#### 自动识别（无需声明）

`getJsonFields()` 复用 `getFieldType()` 的类型推导链路，因此 **MySQL 的 `json` 类型列无需声明 `#[Column]` 即可被自动识别**：

```php
class Order extends Model
{
    // extra 是 MySQL json 列，无需 #[Column(json: true)]
    // getJsonFields() 会通过表结构自动推导返回 ['extra']
}
```

推导结果缓存于 `$typeInferenceCache`，不会重复查询表结构。

#### 需要显式声明的场景

| 场景 | 原因 |
| --- | --- |
| SQLite `TEXT` 列存储 JSON | SQLite 无原生 `json` 类型，`mapDatabaseType` 只认类型名含 `json` 的列 |
| 需要设置 `default` 默认值 | 自动推导不提供默认值 |
| 字段是 `array` / `object` 类型而非 `json` | 区分 `json_decode($v, true)`（关联数组）与 `json_decode($v)`（对象） |

#### `getJsonFields()` 与查询 DSL

`ResourceController` 的查询 DSL 中，`_in` 后缀会通过 `$model->getJsonFields()` 判断字段是否为 JSON 数组：

- **JSON 字段**：使用 `JSON_CONTAINS` 匹配（适合存储数组的 JSON 列，如 `tags` 字段存 `[1,2,3]`）
- **普通字段**：使用 `IN (...)`

```
?tags_in=1,2     → JSON_CONTAINS(`tags`, '1') OR JSON_CONTAINS(`tags`, '2')
?status_in=1,2   → status IN (1, 2)
```

由于 `getJsonFields()` 已支持表结构自动推导，MySQL 的 `json` 列即使未声明 `#[Column]`，`_in` 查询也会正确走 `JSON_CONTAINS`。

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

#### 使用 `#[Append]` 属性（推荐）

`#[Append]` 用于声明**虚拟追加字段**——该字段不对应数据库列，值由方法动态计算，在 `toArray()` / JSON 序列化时自动追加到输出中。

**推荐写法：`#[Append]` 直接标注在方法上**，方法名即字段名，方法本身即为访问器，一次声明完成字段定义与值计算：

```php
use Lychee\orm\attribute\Append;
use Lychee\orm\attribute\Column;

class User extends Model
{
    #[Column(type: 'string')]
    protected string $first_name;

    #[Column(type: 'string')]
    protected string $last_name;

    // 虚拟字段：不入库，方法名 fullName 即字段名
    #[Append]
    public function fullName($value, array $data): string
    {
        return $data['first_name'] . ' ' . $data['last_name'];
    }
}
```

序列化输出：

```json
{
  "id": 1,
  "first_name": "John",
  "last_name": "Doe",
  "fullName": "John Doe"
}
```

> **说明**：`#[Append]` 标注在方法上时，该方法同时承担「字段声明」和「读取访问器」两个职责，无需额外的 `#[Getter]`。

**指定输出字段名**：通过 `name` 参数可自定义 `toArray()` 输出的字段名（例如输出下划线命名），方法名仍保持驼峰可读性：

```php
#[Append(name: 'full_name')]
public function fullName($value, array $data): string
{
    return $data['first_name'] . ' ' . $data['last_name'];
}
// toArray 输出: { "full_name": "John Doe" }
```

也支持标注在属性上的显式形式（需配合 `#[Getter]` 或 `getXxxAttr` 提供值）：

```php
#[Append]
protected string $fullName;

#[Getter]
public function fullName($value, array $data): string { ... }
```

#### 用 `#[Append]` 声明关联自动输出

`#[Append]` 也可以标注在**关联方法**上，使该关联在 `toArray()` 时自动加载并输出，无需每次调用 `->append()` 或 `->with()`：

```php
use Lychee\orm\attribute\Column;
use Lychee\orm\attribute\Append;
use Lychee\orm\relation\HasMany;

class User extends Model
{
    #[Column(type: 'string')]
    protected string $username;

    // posts 关联会在 toArray() 时自动加载
    #[Append]
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id', 'id');
    }
}
```

`toArray()` 输出：

```json
{
  "id": 1,
  "username": "alice",
  "posts": [
    { "id": 1, "user_id": 1, "title": "Post A" },
    { "id": 2, "user_id": 1, "title": "Post B" }
  ]
}
```

> **自动预加载**：`#[Append]` 标注的关联会在查询时**自动预加载**（等价于 `->with()`），无需手动指定，列表查询不会产生 N+1。

`toArray()` 输出：

```json
{
  "id": 1,
  "username": "alice",
  "posts": [
    { "id": 1, "user_id": 1, "title": "Post A" },
    { "id": 2, "user_id": 1, "title": "Post B" }
  ]
}
```

`User::select()` 仅执行 1 条 users 查询 + 1 条 posts 批量查询，所有模型的 `posts` 关联已预加载，`toArray()` 直接输出，无 N+1。

#### 多个追加字段

```php
class Product extends Model
{
    #[Column(type: 'decimal:2')]
    protected float $price;

    #[Column(type: 'int')]
    protected int $stock;

    #[Append]
    protected string $statusText;      // 状态文案

    #[Append]
    protected bool $inStock;           // 是否有货

    public function getStatusTextAttr($value, $data): string
    {
        return $data['status'] === 1 ? '在售' : '下架';
    }

    public function getInStockAttr($value, $data): bool
    {
        return ($data['stock'] ?? 0) > 0;
    }
}
```

#### 隐藏 `#[Append]` 字段：三种策略

`#[Append]` 字段（含关联）默认自动预加载并随 `toArray()` 输出。
不同接口对字段的需求不同（列表页通常要精简、详情页要完整），框架提供三种隐藏方式，按需选择：

| 方法 | 预加载 | 输出 | 适用场景 |
|------|--------|------|----------|
| `$query->without(['posts'])` | ❌ 跳过 | ❌ 隐藏 | 列表接口跳过重关联，省查询 |
| `$query->hidden(['posts'])` | ✅ 仍加载 | ❌ 隐藏 | 数据在别处用，仅此处不输出 |
| `$query->visible([...])` | ✅ 仍加载 | ✅ 仅白名单 | 严格控制输出字段 |

> **核心区别**：`hidden()` 只藏输出不省查询（关联仍预加载）；`without()` 连预加载一起跳过，列表接口首选。

##### 1. `without()` — 跳过预加载 + 隐藏输出（列表页推荐）

```php
// posts 关联既不预加载，也不出现在 toArray 中
User::query()->without(['posts'])->select();
```

`toArray()` 在处理 append 字段时会先检查 `hidden` 列表，命中则直接 `continue`，
连懒加载查询都不会触发，彻底零开销。

##### 2. `hidden()` — 仅隐藏输出

```php
// posts 仍会预加载（数据可能在业务逻辑中用到），但 toArray 不输出
User::query()->hidden(['posts'])->select();
```

##### 3. `visible()` — 白名单模式

```php
// 只输出 id 和 username，其余全部隐藏（含 append 字段和关联）
User::query()->visible(['id', 'username'])->select();
```

##### 在 ResourceController 中按接口配置

继承 `ResourceController` 时，可通过属性声明或方法参数指定每个接口要排除的 `#[Append]` 字段：

```php
class UserController extends ResourceController
{
    // 列表接口排除 posts 关联（不预加载、不输出）
    protected array $indexWithout = ['posts'];

    // 详情接口也排除（如需）
    protected array $readWithout = ['posts'];
}
```

也可在调用 `baseIndex()` / `baseRead()` 时动态传入：

```php
public function index(): JsonResponse
{
    // ... 构建 where
    return $this->baseIndex($where, $current, $pageSize, append: [], without: ['posts']);
}

public function read(int $id): JsonResponse
{
    return $this->baseRead($id, append: [], without: ['posts']);
}
```

> **属性 + 参数合并**：`$indexWithout`/`$readWithout` 属性与 `baseIndex()`/`baseRead()` 的 `$without` 参数会合并去重，两种方式可同时使用。

##### 模型级永久隐藏

如果某个 `#[Append]` 字段在所有接口都不应输出，可在模型的 `$hidden` 属性中声明：

```php
class User extends Model
{
    protected array $hidden = ['internalField'];
}
```

##### 典型场景示例

下面用一个完整的 User 模型演示三种策略的效果差异。模型包含一个虚拟字段 `fullName` 和一个关联 `posts`，均标注了 `#[Append]`：

```php
use Lychee\orm\attribute\Append;
use Lychee\orm\attribute\Column;
use Lychee\orm\Model;
use Lychee\orm\relation\HasMany;

class User extends Model
{
    protected string $name = 'users';

    #[Column(type: 'string')]
    protected string $first_name;

    #[Column(type: 'string')]
    protected string $last_name;

    // 虚拟字段：拼接姓名
    #[Append]
    public function fullName($value, array $data): string
    {
        return $data['first_name'] . ' ' . $data['last_name'];
    }

    // 关联：自动预加载并输出
    #[Append]
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id', 'id');
    }
}
```

**默认输出**（不做任何隐藏）：

```php
$user = User::query()->find(1);
$user->toArray();
```

```json
{
  "id": 1,
  "first_name": "John",
  "last_name": "Doe",
  "fullName": "John Doe",
  "posts": [
    { "id": 10, "user_id": 1, "title": "Hello" },
    { "id": 11, "user_id": 1, "title": "World" }
  ]
}
```

**列表接口**：跳过 posts 关联（省查询），保留 fullName：

```php
// 列表页：posts 不预加载也不输出，fullName 仍输出
User::query()->without(['posts'])->select();
```

```json
{
  "id": 1,
  "first_name": "John",
  "last_name": "Doe",
  "fullName": "John Doe"
}
```

**详情接口**：posts 仍加载（可能在业务逻辑中用到），但不输出：

```php
// 详情页：posts 预加载但不输出
User::query()->hidden(['posts'])->find(1);
```

```json
{
  "id": 1,
  "first_name": "John",
  "last_name": "Doe",
  "fullName": "John Doe"
}
```

**精简接口**：只输出必要字段：

```php
// 仅输出 id 和 fullName
User::query()->visible(['id', 'fullName'])->find(1);
```

```json
{
  "id": 1,
  "fullName": "John Doe"
}
```

> **选择建议**：列表页用 `without()` 跳过重关联；详情页若关联数据在业务逻辑中还需使用，用 `hidden()` 仅藏输出；需要严格字段白名单时用 `visible()`。

#### 运行时临时追加

`#[Append]` 声明的字段始终随 `toArray()` 输出。运行时也可通过 `->append()` 临时追加字段（包括关联）：

```php
User::where('status', 1)
    ->append(['posts'])   // posts 为关联方法，自动预加载
    ->select();
```

> **append 也支持关联**：`append()` 中的名字如果对应模型的关联方法（`hasOne` / `hasMany` / `belongsTo` / `belongsToMany`），
> 会自动加载该关联并随序列化输出；通过查询构造器链式调用时关联会被**预加载**（`IN` 批量查询），避免 N+1。
>
> 关联名支持**蛇形**与**驼峰**两种写法，输出 key 与传入的名字一致：
> ```php
> User::query()->append(['ad_platform'])->find(1);   // 输出 key 为 ad_platform
> User::query()->append(['adPlatform'])->find(1);     // 输出 key 为 adPlatform
> ```

#### `#[Append]` 与 `#[Column]` 的区别

| 对比项 | `#[Column]` | `#[Append]` |
|--------|-------------|-------------|
| 语义 | 真实数据库列 | 虚拟计算字段 |
| 是否入库 | 是，经 `writeTransform` 写入 | 否，不参与写入 |
| 值来源 | 数据库原始值 + 类型转换 | 访问器 `getXxxAttr` 计算 |
| 典型用途 | 声明字段类型、默认值、JSON | 拼接字段、状态文案、计算属性 |

> **何时用哪个**：字段存在于数据库表中 → `#[Column]`；字段由其他列计算得出、不需要入库 → `#[Append]`。

### 获取器 / 修改器

访问器（getter）在读取字段时被调用，修改器（setter）在写入字段时被调用。

**核心心智模型**：大多数场景下你只需要两个属性——

- `#[Append]`：标在方法上，同时完成**虚拟字段声明 + 读取访问器**
- `#[Setter]`：标在方法上，处理**字段写入时的转换**

`#[Getter]` 为可选属性，仅当你需要对 `#[Column]` 真实字段做读取格式化时使用。

#### `#[Append]`：虚拟字段 + 读取（推荐）

`#[Append]` 标在方法上时，方法名即字段名，方法本身即为访问器，一次声明完成字段定义与值计算：

```php
use Lychee\orm\attribute\Column;
use Lychee\orm\attribute\Append;
use Lychee\orm\attribute\Setter;

class User extends Model
{
    #[Column(type: 'string')]
    protected string $first_name;

    #[Column(type: 'string')]
    protected string $last_name;

    // 虚拟字段：方法名 fullName 即字段名，方法即为访问器
    #[Append]
    public function fullName($value, array $data): string
    {
        return $data['first_name'] . ' ' . $data['last_name'];
    }
}
```

#### `#[Setter]`：写入修改器

标在方法上，处理字段写入时的转换（如密码加密、金额格式化）：

```php
use Lychee\orm\attribute\Column;
use Lychee\orm\attribute\Setter;

class User extends Model
{
    #[Column(type: 'string')]
    protected string $password;

    // 方法名 password 即字段名
    #[Setter]
    public function password($value, array $data): string
    {
        return password_hash((string) $value, PASSWORD_DEFAULT);
    }
}
```

写入时 `$user->password = 'secret'` 会自动经过 `password()` 方法加密后再入库。

#### `#[Getter]`：可选的读取格式化

当需要对 `#[Column]` 真实字段做读取格式化（如首字母大写、金额格式化）时，使用 `#[Getter]`：

```php
use Lychee\orm\attribute\Column;
use Lychee\orm\attribute\Getter;

class User extends Model
{
    #[Column(type: 'string')]
    protected string $name;

    #[Getter]
    public function name($value, array $data): string
    {
        return ucfirst((string) $value);
    }
}
```

> **注意**：`#[Append]` 方法本身已是访问器，无需再标注 `#[Getter]`。`#[Getter]` 仅用于 `#[Column]` 字段的读取格式化。

#### 字段名推导规则

未指定 `field` 参数时，方法名按以下规则推导字段名：

| 方法名形式 | 示例 | 推导字段 |
|-----------|------|----------|
| 方法名 = 字段名（推荐） | `fullName` | `fullName` |
| `get`/`set` 前缀 | `getFullName` | `fullName` |
| `get`/`set` 前缀 + `Attr` 后缀 | `getFullNameAttr` | `fullName` |

若方法名不属于以上形式，需显式传入字段名：`#[Getter('fullName')]` / `#[Setter('password')]`。

#### 方法签名约定

- 第一个参数 `$value`：访问器为字段原始值（已过 `readTransform` 类型转换）；修改器为待写入的原始值
- 第二个参数 `$data`：模型全部原始数据数组
- 返回值：访问器返回计算结果；修改器返回处理后的值（会再经过 `writeTransform` 类型转换）

#### 命名约定（向后兼容）

不标注任何属性时，方法名遵循 `getXxxAttr` / `setXxxAttr` 规则也可生效，其中 `Xxx` 为字段名的 StudlyCase 形式：

```php
class User extends Model
{
    // 获取器：getNameAttr 对应字段 name
    public function getNameAttr($value): string
    {
        return ucfirst((string) $value);
    }

    // 修改器：setPasswordAttr 对应字段 password
    public function setPasswordAttr($value): string
    {
        return password_hash((string) $value, PASSWORD_DEFAULT);
    }
}
```

> **优先级**：`#[Getter]` / `#[Setter]` / `#[Append]` 显式绑定的方法优先于命名约定。各种方式可共存。

#### `#[Append]` 完整示例

`#[Append]` 标在方法上即同时完成虚拟字段声明与读取计算，无需额外 `#[Getter]`：

```php
use Lychee\orm\attribute\Column;
use Lychee\orm\attribute\Append;

class Product extends Model
{
    #[Column(type: 'int')]
    protected int $stock;

    #[Append]
    public function statusText($value, array $data): string
    {
        return ($data['stock'] ?? 0) > 0 ? '有货' : '缺货';
    }
}
```

`toArray()` 输出会自动包含 `statusText` 字段，值为 `有货` 或 `缺货`。

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

支持 `hasOne` / `hasMany` / `belongsTo` / `belongsToMany` 四种关联。

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

    // 多对多（用户-角色）
    public function roles(): \Lychee\orm\relation\BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
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
$users = User::with(['profile', 'posts', 'roles'])->select();

// 懒加载：首次访问时查询
$user = User::find(1);
$user->profile; // 自动触发查询
$user->posts;
$user->roles;
```

#### 多对多 belongsToMany

参数说明（均支持默认推导）：

```php
$this->belongsToMany(
    Role::class,        // 目标模型
    'role_user',        // 中间表名（默认按双方表名字母序拼接，如 role_user）
    'user_id',          // 中间表中指向当前模型的外键（默认 {model}_id）
    'role_id',          // 中间表中指向目标模型的外键（默认 {related}_id）
    'id',               // 当前模型的本地键（默认主键）
    'id',               // 目标模型的本地键（默认主键）
);
```

**读取中间表额外字段**：用 `withPivot()` 指定需要读取的中间表字段，结果以数组形式挂在关联模型的 `pivot` 属性上：

```php
public function products(): \Lychee\orm\relation\BelongsToMany
{
    // 读取中间表的 quantity、price 字段
    return $this->belongsToMany(Product::class, 'order_product')
        ->withPivot(['quantity', 'price']);
}

$order = Order::find(1);
foreach ($order->products as $product) {
    echo $product->name;
    echo $product->pivot['quantity']; // 中间表字段（数组）
    echo $product->pivot['price'];
}
```

> **pivot 是数组而非模型**：与 Laravel 不同，Lychee 的 `pivot` 直接以关联数组形式挂载，
> 通过 `$model->pivot['field']` 访问，无需额外的 Pivot 模型。`toArray()` 序列化时会自动包含。

**写入中间表**：

```php
$user = User::find(1);

// 附加单个 / 多个
$user->roles()->attach($roleId);
$user->roles()->attach([1, 2, 3]);

// 附加并写入中间表额外字段
$user->roles()->attach(1, ['expired_at' => '2026-12-31']);
$user->roles()->attach([1 => ['expired_at' => '2026-12-31'], 2 => []]);

// 解除单个 / 多个 / 全部，返回删除的行数
$deleted = $user->roles()->detach($roleId);
$user->roles()->detach([1, 2]);
$user->roles()->detach();

// 同步（以给定列表为准，删除多余的、插入缺失的，已存在的会更新 pivot 字段）
// 返回 ['attached' => [新增的ID], 'detached' => [删除的ID]]
$result = $user->roles()->sync([1, 2, 3]);
$result = $user->roles()->sync([1 => ['expired_at' => '2026-12-31'], 2 => []]);

// 切换（已存在则删除，不存在则插入）
// 返回 ['attached' => [新增的ID], 'detached' => [删除的ID]]
$result = $user->roles()->toggle([1, 2, 3]);
```

> **输入格式约定**：`attach` / `sync` 接受两种数组形式——
> - `[1, 2, 3]`：值为关联 ID，无额外 pivot 字段
> - `[1 => ['expired_at' => '...'], 2 => []]`：键为关联 ID，值为该记录的 pivot 字段

**完整示例：用户-角色 RBAC**

```php
// 模型定义
class User extends Model
{
    public function roles(): \Lychee\orm\relation\BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
    }
}

class Role extends Model
{
    public function users(): \Lychee\orm\relation\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user', 'role_id', 'user_id');
    }
}

// 查询：预加载用户及其角色（2 条 SQL，无 N+1）
$users = User::with('roles')->select();
foreach ($users as $user) {
    echo $user->name . ': ' . implode(',', array_column($user->roles->toArray(), 'name'));
}

// 写入：给用户分配角色
$user = User::find(1);
$user->roles()->sync([1, 2]);           // 覆盖式同步
$user->roles()->attach(3);              // 追加单个
$user->roles()->detach(2);              // 移除单个
$user->roles()->toggle([1, 3]);         // 切换状态
```

**与其他特性配合**：

| 特性 | 用法 | 说明 |
|------|------|------|
| `#[Append]` | 标注在 `roles()` 方法上 | 自动预加载并随 `toArray()` 输出，列表无 N+1 |
| `withCount` | `User::withCount('roles')->select()` | 统计每个用户的角色数，结果为 `roles_count` |
| `without` | `User::without(['roles'])->select()` | 跳过 roles 的预加载与输出（列表页省查询） |
| `hidden` | `User::hidden(['roles'])->select()` | 仅隐藏输出，仍预加载（详情页逻辑中需用时） |

```php
// #[Append] 自动输出角色列表
class User extends Model
{
    #[\Lychee\orm\attribute\Append]
    public function roles(): \Lychee\orm\relation\BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
    }
}

// 列表接口排除 roles（不预加载、不输出）
$list = User::without(['roles'])->select();
```

> **关联名支持蛇形 / 驼峰**：`with()`、懒加载属性访问、`append()` 中的关联名既可用方法原名（驼峰，如 `adPlatform`），
> 也可用蛇形（如 `ad_platform`），框架会自动把 `ad_platform` 解析到 `adPlatform()` 方法。
> 输出 / 缓存 key 与传入名一致，便于前端按蛇形字段对接。
>
> ```php
> class Ad extends Model
> {
>     public function adPlatform(): \Lychee\orm\relation\BelongsTo
>     {
>         return $this->belongsTo(AdPlatform::class, 'ad_platform_id');
>     }
> }
>
> // 以下两种写法等价，都能命中 adPlatform() 关联
> Ad::with('adPlatform')->select();
> Ad::with('ad_platform')->select();   // 蛇形
> $ad->ad_platform;                    // 懒加载，蛇形属性访问
> ```

### 关联计数 withCount

需要在列表中展示关联记录数（如"每个用户的文章数"）时，用 `withCount()` 预加载计数，
结果以 `{relation}_count` 字段附加到模型实例，避免 N+1 查询。

```php
// 单个关联计数
$users = User::withCount('posts')->select();
foreach ($users as $user) {
    echo $user->posts_count; // 该用户的文章数
}

// 同时统计多个关联
$users = User::withCount(['posts', 'orders'])->select();
echo $users[0]->posts_count;
echo $users[0]->orders_count;

// 单条查询同样支持
$user = User::withCount('posts')->find(1);
echo $user->posts_count;

// toArray / JSON 序列化时会自动包含 {relation}_count 字段
```

> 内部对每个关联执行一条 `GROUP BY` 聚合查询统计数量，关联数为 0 的模型也会正确写入 `0`。
> `withCount` 结果仅附加到内存中的模型实例，不会写入数据库（不在表字段白名单内，`save()` 时自动忽略）。

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

### 级联删除

通过在模型上声明 `$cascade` 属性，可以在删除模型时自动连带删除其关联记录，避免手动逐层清理产生孤儿数据。

```php
use Lychee\orm\Model;
use Lychee\orm\relation\HasMany;
use Lychee\orm\relation\HasOne;

class User extends Model
{
    // 声明需要级联删除的关联方法名
    protected array $cascade = ['orders', 'profile'];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'user_id', 'id');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class, 'user_id', 'id');
    }
}
```

声明后正常删除即可，级联自动生效：

```php
$user = User::find(1);
$user->delete();   // 自动删除 user 及其所有 orders、profile
```

#### 关联类型行为

| 关联类型 | `getResults()` 返回 | 级联行为 |
|----------|---------------------|----------|
| `hasMany` | `Collection` | 遍历每条子记录调用 `delete()` |
| `hasOne` | `?Model` | 单条关联记录调用 `delete()` |
| `belongsTo` | `?Model` | 单条关联记录调用 `delete()`（慎用，会删除父记录） |

#### 软删除与强制删除

级联删除会跟随父模型的删除方式：

```php
// 父模型软删除 → 子记录也软删除（若子模型启用了 SoftDelete）
$user->delete();

// 父模型强制硬删除 → 子记录也硬删除
$user->forceDelete();

// destroy 同样支持级联，第二参数传 true 则全部硬删除
User::destroy([1, 2, 3]);        // 软删除（含级联）
User::destroy([1, 2, 3], true);  // 硬删除（含级联）
```

#### 嵌套级联

子模型自身也可以声明 `$cascade`，删除时会递归向下清理：

```php
class Order extends Model
{
    use SoftDelete;

    protected array $cascade = ['items'];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id');
    }
}

// 删 user → 删 user 的 orders → 删每个 order 的 items
User::find(1)->delete();
```

#### 事务与失败回滚

- 单条 `delete()` / `forceDelete()`：级联 + 自身删除在**同一个事务**内执行。
- `destroy()` 批量删除：整个批次在**同一个事务**内执行。
- 任一关联记录删除失败（例如其 `before_delete` 事件返回 `false`），会抛出 `DbException` 并**回滚整个事务**，父记录也不会被删除。

```php
Order::event('before_delete', function ($order) {
    if ($order->status === 'paid') {
        return false;  // 已支付订单禁止删除
    }
});

// 只要有一个 paid 订单，整次删除失败回滚
User::find(1)->delete();
```

#### 事件触发

级联删除的子记录会走完整的模型生命周期，触发其自身的 `before_delete` / `after_delete` 事件（`forceDelete` 不触发事件，与原有行为一致）。

#### 注意事项

- 级联删除基于**实例逐条删除**（非批量 SQL），会触发子模型事件并支持嵌套级联，但在数据量极大时会有 N+1 性能开销。
- 对于超大数据量的级联清理，建议在数据库层配置外键 `ON DELETE CASCADE`，或自行使用查询构造器批量删除。

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
    protected static function onBeforeInsert(User $user): void
    {
        $user->uuid ??= uuid();
    }

    // 更新前拦截
    protected static function onBeforeUpdate(User $user): bool|null
    {
        if ($user->name === 'forbidden') {
            return false; // 阻断本次更新
        }
        return null;
    }

    // 删除后级联清理
    protected static function onAfterDelete(User $user): void
    {
        Profile::destroy($user->id);
    }
}
```

事件名到方法名的映射规则：下划线转 StudlyCase 并加 `on` 前缀，例如 `before_insert` → `onBeforeInsert`。方法推荐声明为 `protected static`（框架在 `Model` 父类作用域内调用，可正常访问子类 `protected` 成员），也可使用 `public static`；**无需继承任何额外接口或 trait**，框架会自动检测并调用。

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
