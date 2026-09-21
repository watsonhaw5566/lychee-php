# 数据迁移 Migration

轻量级数据库迁移与数据填充模块。

## 目录结构

```
database/
├── migrations/    # 迁移文件
└── seeders/       # 数据填充文件
```

## 命令

```bash
php lee migrate:run                   # 执行所有未执行的迁移
php lee migrate:rollback              # 回滚全部迁移
php lee migrate:rollback --steps=1    # 回滚最近 1 步
php lee migrate:create create_users_table   # 创建迁移模板文件
php lee seed:run                      # 执行所有 Seeder
php lee seed:create UserSeeder        # 创建 Seeder 模板文件
```

`migrate:create` 会自动生成 `YYYYMMDDHHMMSS_名称.php` 格式的文件名，并根据名称推导类名（`create_users_table` → `CreateUsersTableMigration`）。`seed:create` 会自动追加 `Seeder` 后缀生成文件名与类名（`User` → `UserSeeder.php` / `UserSeeder`）。

## 编写迁移

文件名格式：`YYYYMMDDHHMMSS_描述.php`，类名为描述的驼峰形式。

```php
// database/migrations/20240101000000_create_users_table.php
<?php

use Lychee\migration\Migration;

class CreateUsersTableMigration extends Migration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('name', 'string', ['length' => 100])
            ->addColumn('email', 'string', ['length' => 255])
            ->addTimestamps()
            ->addSoftDelete()
            ->create();
    }

    public function down(): void
    {
        $this->table('users')->drop();
    }
}
```

## 表构建器常用方法

```php
$table = $this->table('table_name');

// 字段
$table->addColumn('name', 'string', ['length' => 100]);
$table->addColumn('age', 'integer', ['unsigned' => true]);
$table->addColumn('price', 'decimal', ['precision' => 10, 'scale' => 2]); // 默认为10位数字，2位小数
$table->addColumn('status', 'boolean', ['default' => 0]); // 默认值为0
$table->addColumn('content', 'text');
$table->addColumn('data', 'json');

// 便捷方法
$table->addTimestamps();        // create_time, update_time（TIMESTAMP 类型）
$table->addDatetimes();         // create_time, update_time（DATETIME 类型，不受 2038 年与时区限制）
$table->addSoftDelete();        // delete_time

// 索引
$table->addIndex(['email'], ['type' => 'UNIQUE']);

// 操作
$table->create();               // 建表
$table->drop();                 // 删表
$table->update();               // 应用改表变更
$table->removeColumn('field');
$table->changeColumn('field', 'varchar', ['length' => 200]);
```

## 数据填充

```php
// database/seeders/UserSeeder.php
<?php

use Lychee\migration\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $this->insert('users', [
            'name'  => 'admin',
            'email' => 'admin@example.com',
        ]);

        $this->insertBatch('users', [
            ['name' => 'a', 'email' => 'a@b.com'],
            ['name' => 'b', 'email' => 'b@c.com'],
        ]);
    }
}
```

## 数据库连接

使用 `config/database.php` 中默认连接配置的数据库。若数据库未配置或连接失败，迁移命令会提示 `Database connection is not available. Please configure database first`。
