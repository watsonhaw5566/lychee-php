# 数据迁移 Migration

轻量级数据库迁移与数据填充模块。

## 目录结构

```
database/
├── migrations/    # 迁移文件
├── seeders/       # 数据填充文件
└── sql/           # 裸 SQL 种子脚本（本地导入）
```

## 命令

```bash
php lee migrate:run                   # 执行所有未执行的迁移
php lee migrate:rollback              # 回滚全部迁移
php lee migrate:rollback --steps=1    # 回滚最近 1 步
php lee migrate:create create_users_table   # 创建迁移模板文件
php lee migrate:reverse                     # 从现有数据库反向生成迁移文件（每张表一个文件）
php lee migrate:reverse -a                  # 所有表合并到一个迁移文件
php lee migrate:reverse -t users,posts      # 仅生成指定表（逗号分隔）
php lee migrate:reverse -c production       # 使用指定数据库连接
php lee migrate:reverse --record            # 生成后标记为已执行（已有库接管迁移体系）
php lee seed:run                      # 执行所有 Seeder
php lee seed:create UserSeeder        # 创建 Seeder 模板文件
php lee seed:sql                      # 导入 database/sql 下未执行的 SQL 脚本
php lee seed:sql cod_regions.sql      # 仅导入指定文件
php lee seed:sql --force              # 已导入过的文件也重新执行
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
$table->addColumn('nickname', 'string', ['after' => 'name']); // 指定字段位于 name 之后（仅 MySQL，SQLite 不支持列定位、自动忽略）

// 便捷方法
$table->addTimestamps();        // create_time, update_time（TIMESTAMP 类型）
$table->addDatetimes();         // create_time, update_time（DATETIME 类型，不受 2038 年与时区限制）
$table->addSoftDelete();        // delete_time

// 索引
$table->addIndex(['email'], ['type' => 'UNIQUE']);

// 外键（引用表名会自动应用表前缀；仅在 create() 建表时生效）
$table->addForeignKey(['role_id'], 'roles', ['id'], ['name' => 'fk_role', 'onDelete' => 'CASCADE']);

// 操作
$table->create();               // 建表
$table->drop();                 // 删表
$table->update();               // 应用改表变更
$table->removeColumn('field');
$table->changeColumn('field', 'varchar', ['length' => 200]);
```

### 修改已有表结构

对已存在的表，通过 `addColumn` / `removeColumn` / `changeColumn` / `renameColumn` 描述变更，最后调用 `update()` 应用：

```php
public function up(): void
{
    $this->table('users')
        ->addColumn('nickname', 'string', ['length' => 50, 'after' => 'name']) // 新增字段并置于 name 之后
        ->removeColumn('status')                                               // 删除字段
        ->changeColumn('email', 'string', ['length' => 200])                   // 修改字段类型
        ->renameColumn('name', 'username')                                    // 重命名字段
        ->update();                                                            // 应用变更
}
```

说明：

- `after` 仅对 MySQL 生效（`AFTER` 子句），`changeColumn` 中同样支持；SQLite 不支持列定位，该选项会被忽略；
- MySQL 下多个变更会合并为一条 `ALTER TABLE` 语句执行；SQLite 下 `changeColumn` 通过"建临时表-拷贝数据-替换"实现。

## 从现有数据库反向生成迁移

如果你正在将一个已有数据库的项目改造为使用迁移，`migrate:reverse` 可以读取当前数据库的表结构，反向生成迁移文件：

```bash
php lee migrate:reverse                    # 每张表生成单独的迁移文件
php lee migrate:reverse -a                 # 所有表合并到一个迁移文件（适用于新项目初始化）
php lee migrate:reverse -t users           # 只为指定的表生成迁移（逗号分隔多张表）
php lee migrate:reverse -c production      # 使用指定的数据库连接
php lee migrate:reverse --ignore=cache,sessions   # 排除指定的表
```

支持的选项：

| 选项 | 短 | 说明 |
| --- | --- | --- |
| `--all` | `-a` | 所有表合并到一个迁移文件 |
| `--table` | `-t` | 仅生成指定表（逗号分隔） |
| `--connection` | `-c` | 指定数据库连接，缺省使用默认连接 |
| `--ignore` | — | 排除指定表（逗号分隔） |
| `--force` | `-f` | 目标文件已存在时覆盖（默认跳过并告警） |
| `--record` | `-r` | 生成后写入 `migrations` 表标记为已执行 |

行为说明：

- 表名会自动剥离配置的表前缀，生成的迁移里写逻辑表名（框架在执行时会重新加前缀）；
- 存在外键依赖的表会按依赖顺序生成（被引用的表排在前面），单文件模式下 `down()` 逆序删表；
- 单列 `id` 自增主键会复用构建器默认行为，输出更简洁；非 `id` 主键或联合主键会显式声明；
- `--record` 适用于已有库接管：反向生成后直接登记为已执行，后续 `migrate:run` 不会重复执行这批基线迁移。

生成的迁移文件示例：

```php
// database/migrations/20240101000000_create_users_table.php
<?php

declare(strict_types=1);

use Lychee\migration\Migration;

class CreateUsersTableMigration extends Migration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('name', 'string', ['length' => 100])
            ->addColumn('email', 'string', ['length' => 255])
            ->addIndex(['email'], ['type' => 'UNIQUE', 'name' => 'uk_email'])
            ->addForeignKey(['role_id'], 'roles', ['id'], ['name' => 'fk_role', 'onDelete' => 'CASCADE'])
            ->create();
    }

    public function down(): void
    {
        $this->table('users')->drop();
    }
}
```

> 注意：自动生成的迁移文件可能需要手动调整，特别是外键关系、某些特殊字段类型及索引配置。建议生成后仔细检查并测试迁移的正确性。

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

## SQL 脚本导入

当数据来自数据库导出工具（如几十万行 `INSERT` 的 SQL 文件），不适合改写为 PHP Seeder 时，可将 `.sql` 文件放入 `database/sql/` 目录，通过 `seed:sql` 直接导入：

```bash
php lee seed:sql                  # 按文件名排序，依次执行所有未导入的脚本
php lee seed:sql cod_regions.sql  # 只导入指定文件
php lee seed:sql --force          # 忽略导入记录，重新执行
```

执行规则：

- 文件按文件名排序依次执行，建议使用 `001_xxx.sql`、`002_xxx.sql` 形式的序号前缀控制顺序；
- 已成功导入的文件名记录在数据库的 `sql_seeds` 表中，再次执行自动跳过；执行失败的文件不会被记录；
- SQL 按词法解析逐条执行，字符串与注释（`-- `、`#`、`/* */`）中的分号不会被误切；文件采用流式读取，超大文件不会占用过多内存；
- DML 语句按每 1000 条分批提交以保证导入速度；`CREATE/ALTER/DROP` 等 DDL 语句会先提交当前批次再单独执行（MySQL 的 DDL 会隐式提交）；
- 失败时回滚当前批次并中断，错误信息包含文件名、语句序号、行号与出错语句片段。

注意事项：

- 该目录用于本地环境的数据导入，文件不纳入版本库时请自行加入 `.gitignore`；
- SQL 脚本不支持自动回滚，也不会应用数据表前缀，表名需写完整；
- 大型脚本建议编写为幂等形式，以便失败或重跑时使用 `--force` 安全重试：

```sql
CREATE TABLE IF NOT EXISTS `fc_cod_regions` ( ... );
INSERT IGNORE INTO `fc_cod_regions` VALUES ( ... );
```

## 数据库连接

使用 `config/database.php` 中默认连接配置的数据库。若数据库未配置或连接失败，迁移命令会提示 `Database connection is not available. Please configure database first`。
