# 数据库 ORM

基于 ThinkORM，支持查询构造器、模型、关联。

## 配置

创建 `config/database.php`：

```php
return [
    'default' => 'mysql',
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
        ],
        'sqlite' => [
            'type'     => 'sqlite',
            'database' => runtime_path('app.sqlite'),
        ],
    ],
];
```

## 查询构造器

```php
use think\facade\Db;

$users = Db::table('users')->where('status', 1)->select();
$user  = Db::table('users')->find(1);

Db::table('users')->insert(['name' => 'a']);
Db::table('users')->where('id', 1)->update(['name' => 'b']);
Db::table('users')->where('id', 1)->delete();
```

## 模型

```php
// app/model/User.php
namespace App\model;

use think\Model;

class User extends Model
{
    protected $table = 'users';
}

// 使用
User::find(1);
User::where('status', 1)->select();
User::create(['name' => 'a']);
```
