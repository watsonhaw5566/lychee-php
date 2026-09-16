# 数据库 ORM

框架集成了 [topthink/think-orm](https://github.com/top-think/think-orm) 作为数据库抽象层，支持查询构造器、模型、关联、事务等能力。底层由 `think\DbManager` 统一管理连接，已在容器中注册为 `db` 服务。

> 完整 API 与高级用法请参考 [ThinkORM 官方文档](https://doc.thinkphp.cn/v8_0/orm.html)。

## 配置

在 `config/database.php` 中定义连接，`default` 指定默认连接名：

```php
return [
    // 默认连接
    'default' => 'mysql',

    // 连接列表
    'connections' => [
        'mysql' => [
            'type'     => 'mysql',
            'hostname' => '127.0.0.1',
            'hostport' => 3306,
            'database' => 'app',
            'username' => 'root',
            'password' => '',
            'charset'  => 'utf8mb4',
            'prefix'   => '',
            'debug'    => false,
        ],
        'sqlite' => [
            'type'     => 'sqlite',
            'database' => runtime_path('app.sqlite'),
            'prefix'   => '',
            'debug'    => false,
        ],
    ],
];
```

框架启动时会自动读取该配置并初始化 `DbManager`，同时绑定到模型基类（`think\Model`）。

## 获取数据库实例

有两种方式获取数据库管理器：

```php
use think\facade\Db;

// 方式一：Facade 门面（推荐，IDE 友好）
Db::table('users')->select();

// 方式二：从容器解析
$db = app('db');
$db->table('users')->select();
```

## 查询构造器

通过 `Db::table('表名')` 获取查询构造器，支持链式调用。

### 查询

```php
use think\facade\Db;

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

// 模糊查询
Db::table('users')->where('name', 'like', '%张%')->select();

// IN 查询
Db::table('users')->whereIn('id', [1, 2, 3])->select();

// 区间查询
Db::table('users')->whereBetween('age', [18, 30])->select();

// 聚合
$count = Db::table('users')->where('status', 1)->count();
$max   = Db::table('users')->max('age');

// 指定字段
Db::table('users')->field('id,name')->select();

// 分页
Db::table('users')->paginate(15);
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
Db::table('users')->where('id', 1)->inc('score', 5)->update();
Db::table('users')->where('id', 1)->dec('score', 2)->update();
```

### 删除

```php
// 按条件删除
Db::table('users')->where('id', 1)->delete();

// 按主键删除
Db::table('users')->delete(1);
```

### 原生查询

```php
// 读
Db::query('SELECT * FROM users WHERE status = ?', [1]);

// 写
Db::execute('UPDATE users SET status = 0 WHERE id = ?', [1]);
```

## 模型

模型继承 `think\Model`，把表操作封装为对象。模型会自动关联到 `DbManager`，无需手动注入连接。

### 定义模型

```php
// app/model/User.php
namespace app\model;

use think\Model;

/**
 * @property int    $id
 * @property string $name
 * @property string $email
 * @property int    $age
 */
class User extends Model
{
    // 表名（不含前缀）。不设置时默认使用类名的 snake_case 复数
    protected $name = 'users';

    // 表前缀，留空则使用配置中的 prefix
    // protected $prefix = '';

    // 主键，默认 id
    // protected $pk = 'id';
}
```

### 模型 CRUD

```php
use app\model\User;

// 查询
$user = User::find(1);                 // 按主键
$list = User::where('status', 1)->select();
$all  = User::select();

// 新增（静态 create）
$user = User::create([
    'name'  => 'Tom',
    'email' => 'tom@example.com',
    'age'   => 20,
]);
echo $user->id; // 自增 ID

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
```

### 模型可在控制器中通过依赖注入获取

```php
use app\model\User;

class UserController
{
    public function __construct(
        private readonly User $user,
    ) {
    }

    public function index()
    {
        return $this->user->where('status', 1)->select();
    }
}
```

### 获取器 / 修改器

```php
class User extends Model
{
    // 获取器：读取时自动处理
    public function getNameAttr($value)
    {
        return ucfirst($value);
    }

    // 修改器：写入时自动处理
    public function setPasswordAttr($value)
    {
        return password_hash($value, PASSWORD_DEFAULT);
    }
}
```

### 关联

```php
class User extends Model
{
    // 一对一
    public function profile()
    {
        return $this->hasOne(Profile::class, 'user_id');
    }

    // 一对多
    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id');
    }

    // 多对多
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_role', 'role_id', 'user_id');
    }
}

// 预加载（避免 N+1）
$users = User::with(['profile', 'posts'])->select();
```

## 事务

```php
use think\facade\Db;

Db::transaction(function () {
    Db::table('users')->where('id', 1)->dec('balance', 100)->update();
    Db::table('orders')->insert(['user_id' => 1, 'amount' => 100]);
});
```

手动控制事务：

```php
Db::startTrans();
try {
    Db::table('users')->where('id', 1)->dec('balance', 100)->update();
    Db::table('orders')->insert(['user_id' => 1, 'amount' => 100]);

    Db::commit();
} catch (\Throwable $e) {
    Db::rollback();
    throw $e;
}
```

## 切换连接

```php
// 使用非默认连接
Db::connect('sqlite')->table('users')->select();
```

## 更多用法

ThinkORM 还支持软删除、模型事件、查询范围、JSON 字段、多数据库切换等高级特性，完整文档请参考：

- [ThinkORM 官方文档](https://doc.thinkphp.cn/v8_0/orm.html)
- [查询构造器](https://doc.thinkphp.cn/v8_0/orm/query.html)
- [模型](https://doc.thinkphp.cn/v8_0/orm/model.html)
- [关联](https://doc.thinkphp.cn/v8_0/orm/relation.html)
- [事务](https://doc.thinkphp.cn/v8_0/orm/transaction.html)
