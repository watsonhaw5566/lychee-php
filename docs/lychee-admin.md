# lychee-admin

lychee-php 的后台管理插件，安装后即可获得开箱即用的基础 admin 后台功能（前后端不分离）。

基于动态资源管理设计，类似 Django Admin：注册模型即可自动获得 CRUD 界面。

## 安装

```bash
composer require watsonhaw/lychee-admin
```

## 启用

在项目 `config/plugin.php` 中注册插件入口类：

```php
<?php

return [
    'providers' => [
        LycheeAdmin\AdminServiceProvider::class,
    ],
];
```

插件会在框架启动时自动完成：

- 绑定 `AdminManager` 到容器
- 注册后台路由（`/admin`）
- 注册 `@admin` Twig 视图命名空间
- 注册内置资源（用户、角色、菜单、权限）
- 注册 `super_admin` 鉴权中间件
- 注册插件的迁移与 Seeder 路径
- 注册 `admin:publish` 控制台命令

## 数据库

插件自带迁移与数据填充，执行以下命令建表并写入默认管理员：

```bash
# 执行迁移，创建 admin / admin_role / admin_menu / admin_permission 表
php lee migrate:run

# 执行数据填充，插入默认超级管理员（admin / admin123）
php lee seed:run
```

## 目录结构

```
lychee-admin/
├── asset/                  # 静态资源（发布到 public/lychee/）
│   ├── admin/              # 后台 CSS、图片
│   └── component/          # layui、pear 组件
├── database/
│   ├── migrations/         # 数据库迁移
│   └── seeders/            # 数据填充
├── src/
│   ├── AdminResource.php   # 资源配置基类
│   ├── AdminManager.php    # 资源注册表
│   ├── AdminController.php # 通用 CRUD 控制器
│   ├── AdminServiceProvider.php
│   ├── middleware/         # 鉴权中间件
│   ├── model/              # 数据模型
│   └── resource/           # 内置资源
└── view/                   # Twig 模板
```

## 静态资源发布

`asset/` 目录下的静态资源需要发布到项目的 `public/lychee/` 目录。

> 注意：静态资源目录使用 `lychee` 而非 `admin`，因为 `/admin` 已被用作后台路由前缀，若静态资源也放在 `public/admin/` 会导致路由失效。

提供了 `admin:publish` 控制台命令方便发布：

```bash
# 复制静态资源到 public/lychee/
php lee admin:publish

# 强制覆盖已存在的文件
php lee admin:publish --force

# 创建符号链接（开发环境推荐，修改资源即时生效）
php lee admin:publish --link
```

也可以手动操作：

```bash
# 方式一：符号链接（开发环境推荐）
ln -s vendor/watsonhaw/lychee-admin/asset public/lychee

# 方式二：复制（生产环境）
cp -r vendor/watsonhaw/lychee-admin/asset/* public/lychee/
```

生产环境建议通过 Nginx 直接映射静态资源目录。

## 使用

访问 `/admin`，使用默认账号 `admin` / `admin123` 登录。

## 注册资源

每个需要在后台管理的模型对应一个继承 `AdminResource` 的子类，声明配置属性即可自动获得 CRUD 界面，无需编写控制器。

```php
<?php

namespace App\Admin;

use LycheeAdmin\AdminResource;
use App\Model\Article;

class ArticleAdmin extends AdminResource
{
    protected string $model = Article::class;
    protected string $title = '文章';
    protected string $icon  = 'layui-icon layui-icon-read';
    protected string $group = '内容管理';

    /** 列表显示字段 */
    protected array $listFields = ['id', 'title', 'category_id', 'status', 'create_time'];

    /** 表单字段：字段名 => 类型（或完整配置数组） */
    protected array $formFields = [
        'title'       => 'text',
        'category_id' => ['type' => 'select', 'options' => [1 => '技术', 2 => '生活']],
        'content'     => 'textarea',
        'status'      => ['type' => 'radio', 'options' => [1 => '发布', 0 => '草稿']],
    ];

    protected array $searchFields = ['title'];
    protected array $filterFields = ['status'];
}
```

在 `AdminServiceProvider` 或插件启动逻辑中注册：

```php
app(AdminManager::class)->register(ArticleAdmin::class);
```

### 字段标签

列表表头、表单 label、搜索框占位符默认使用英文字段名。通过 `$fieldLabels` 声明中文字段名：

```php
protected array $fieldLabels = [
    'title'       => '标题',
    'category_id' => '分类',
    'content'     => '内容',
    'status'      => '状态',
];
```

**解析优先级**：

1. 子类声明的 `$fieldLabels`
2. 内置通用映射（`id` → ID、`create_time` → 创建时间、`update_time` → 更新时间）
3. 字段名本身（兜底）

> 字段标签完全由代码配置驱动，不读取数据库 comment，因此对所有数据库类型通用。未声明的字段会直接显示英文字段名。

### 支持的表单字段类型

`text`、`textarea`、`number`、`password`、`select`、`radio`、`checkbox`、`switch`、`image`、`file`、`richtext`、`date`。

## License

MIT
