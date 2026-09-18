# lychee-admin 后台管理插件

`lychee-admin` 是 lychee-php 的后台管理插件，安装后即可获得开箱即用的基础 admin 后台功能（前后端不分离）。

基于 **动态资源管理** 设计，类似 Django Admin：注册模型即可自动获得 CRUD 界面，无需编写控制器。

## 特性

- 开箱即用：安装后注册模型即可管理
- 动态资源路由：一个通用控制器处理所有资源的 CRUD
- 可扩展：继承 `AdminResource` 自定义列表字段、表单字段、搜索、筛选
- 可覆盖：同模型后注册的资源覆盖内置实现
- super_admin 鉴权：内置超级管理员权限控制
- 复用 pear-admin 前端样式
- 复用框架迁移与 seed 模块建表

## 安装

```bash
composer require watsonhaw/lychee-admin
```

## 启用

在 `config/plugin.php` 中注册插件入口：

```php
return [
    'providers' => [
        \LycheeAdmin\AdminServiceProvider::class,
    ],
];
```

插件启动时会自动完成：

- 注册 `AdminController` 路由（`/admin/*`）
- 注册 Twig 视图命名空间 `@admin`
- 注册内置资源（管理员、角色、菜单、权限）
- 注册 `AdminAuthMiddleware` 到全局中间件
- 注册包内的迁移与 seed 路径到 `MigrationManager`

## 数据库

插件复用框架的迁移与 seed 模块。建表与初始化数据：

```bash
# 执行迁移（创建 admin、admin_role、admin_menu、admin_permission 表）
php lee migrate

# 执行 seed（插入默认超级管理员）
php lee db:seed
```

默认账号：

- 用户名：`admin`
- 密码：`admin123`

## 访问

浏览器打开 `/admin`，使用默认账号登录。

## 架构

```
用户应用                          lychee-admin 包
─────────                        ────────────────
ProductAdmin ──register──→ AdminManager ──┐
                                          │
UserAdmin(内置) ──────────→ AdminManager ─┤
                                          ▼
                                 AdminController (通用)
                                          │
                    ┌─────────────────────┼─────────────────────┐
                    ▼                     ▼                     ▼
              列表页模板            表单页模板            删除确认页
```

### 核心类

| 类 | 职责 |
|----|------|
| `AdminResource` | 资源配置基类，声明列表字段、表单字段、搜索、筛选等 |
| `AdminManager` | 资源注册表，管理所有已注册的 `AdminResource` |
| `AdminController` | 通用 CRUD 控制器，通过 `{resource}` 路由参数动态分发 |
| `AdminServiceProvider` | 插件入口，向框架注入路由、视图、资源、中间件 |

## 内置资源

插件内置四个系统管理资源：

| 资源 | 模型 | 说明 |
|------|------|------|
| 管理员 | `LycheeAdmin\model\Admin` | 后台账号管理 |
| 角色 | `LycheeAdmin\model\Role` | 角色与权限分配 |
| 菜单 | `LycheeAdmin\model\Menu` | 后台菜单管理 |
| 权限 | `LycheeAdmin\model\Permission` | 权限标识管理 |

## 自定义资源

只需两步即可将任意模型纳入后台管理。

### 第一步：定义资源类

```php
namespace app\admin\resource;

use LycheeAdmin\AdminResource;
use app\model\Product;
use think\Model;

class ProductAdmin extends AdminResource
{
    protected string $model = Product::class;
    protected string $title = '商品管理';
    protected string $icon  = 'layui-icon layui-icon-cart';
    protected string $group = '商品';

    protected array $listFields = ['id', 'name', 'price', 'stock', 'create_time'];

    protected array $formFields = [
        'name'     => 'text',
        'price'    => 'number',
        'stock'    => 'number',
        'status'   => ['type' => 'radio', 'options' => [1 => '上架', 0 => '下架']],
    ];

    protected array $searchFields = ['name'];

    protected function beforeSave(array $data): array
    {
        // 价格元转分
        if (isset($data['price'])) {
            $data['price'] = (int) ($data['price'] * 100);
        }
        return $data;
    }

    protected function formatList(Model $item): array
    {
        $data = $item->toArray();
        $data['price'] = number_format($data['price'] / 100, 2);
        return $data;
    }
}
```

### 第二步：注册资源

在应用的服务提供者或 bootstrap 中注册：

```php
use LycheeAdmin\AdminManager;

$container->get(AdminManager::class)->register(\app\admin\resource\ProductAdmin::class);
```

注册后，访问 `/admin/Product` 即可管理商品。

## 覆盖内置资源

同模型的资源后注册会覆盖先注册的，因此可以继承内置资源并覆盖：

```php
namespace app\admin\resource;

use LycheeAdmin\resource\UserAdmin as BaseUserAdmin;
use think\Model;

class MyUserAdmin extends BaseUserAdmin
{
    protected array $listFields = ['id', 'username', 'phone', 'status', 'last_login'];

    protected function afterSave(Model $model): void
    {
        // 创建用户后发送欢迎邮件
        // Mail::to($model->email)->send(...);
    }
}
```

```php
$container->get(AdminManager::class)->register(\app\admin\resource\MyUserAdmin::class);
```

## 字段配置

### `$listFields` 列表显示字段

```php
protected array $listFields = ['id', 'name', 'price', 'create_time'];
```

### `$formFields` 表单字段配置

支持简写和完整配置两种写法：

```php
protected array $formFields = [
    'name'     => 'text',                                    // 简写
    'status'   => ['type' => 'select', 'options' => [1 => '启用', 0 => '禁用']], // 完整配置
];
```

支持的字段类型：

| 类型 | 说明 |
|------|------|
| `text` | 单行文本（默认） |
| `textarea` | 多行文本 |
| `number` | 数字 |
| `password` | 密码（编辑时留空不修改） |
| `select` | 下拉选择，需配置 `options` |
| `radio` | 单选，需配置 `options` |
| `checkbox` | 多选，需配置 `options` |
| `switch` | 开关 |
| `image` | 图片上传 |
| `file` | 文件上传 |
| `richtext` | 富文本 |
| `date` | 日期 |

### `$searchFields` 搜索字段

列表页顶部搜索框，支持模糊匹配：

```php
protected array $searchFields = ['name', 'code'];
```

### `$filterFields` 筛选字段

列表页精确筛选条件：

```php
protected array $filterFields = ['status'];
```

### 权限开关

```php
protected bool $canCreate = true;   // 是否允许新增
protected bool $canUpdate = true;   // 是否允许编辑
protected bool $canDelete = true;   // 是否允许删除
```

## 钩子方法

`AdminResource` 提供以下钩子方法，子类可覆盖以注入自定义逻辑：

| 方法 | 调用时机 | 用途 |
|------|---------|------|
| `indexQuery(Query $query)` | 列表查询前 | 追加查询条件或关联预加载 |
| `beforeSave(array $data)` | 保存前（新增/编辑） | 数据预处理 |
| `afterSave(Model $model)` | 保存后（新增/编辑） | 保存后处理 |
| `beforeDelete(Model $model)` | 删除前 | 删除前校验或级联处理 |
| `formatList(Model $item)` | 列表数据格式化 | 枚举转文本、时间格式化等 |

## 权限控制

插件通过 `AdminAuthMiddleware` 实现 super_admin 鉴权：

- 登录时将 `is_super` 标记写入 token
- 所有 `/admin/*` 请求（除登录页外）校验 token 中的 `is_super`
- 非 super_admin 返回 401 或重定向到登录页

管理员表中的 `is_super` 字段为 `1` 表示超级管理员。

## 路由

`AdminController` 注册以下路由：

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/admin` | 仪表盘 |
| GET | `/admin/login` | 登录页 |
| POST | `/admin/login` | 登录提交 |
| POST | `/admin/logout` | 退出登录 |
| GET | `/admin/{resource}` | 资源列表页 |
| GET | `/admin/{resource}/data` | 资源列表数据（AJAX） |
| GET | `/admin/{resource}/create` | 新增表单页 |
| POST | `/admin/{resource}` | 保存新增 |
| GET | `/admin/{resource}/{id}/edit` | 编辑表单页 |
| PUT | `/admin/{resource}/{id}` | 保存编辑 |
| DELETE | `/admin/{resource}/{id}` | 删除 |

`{resource}` 参数支持模型类名（含命名空间）或模型短名（如 `Product`）。

## 前端资源

插件复用 pear-admin 前端资源，静态文件统一位于 `lychee-admin/asset/` 目录下，发布后对应项目的 `public/admin/` 目录。

### 发布静态资源

使用 `admin:publish` 命令将静态资源发布到 `public/admin/`：

```bash
# 复制静态资源到 public/admin/
php lee admin:publish

# 强制覆盖已存在的文件
php lee admin:publish --force

# 创建符号链接（开发环境推荐，修改资源即时生效）
php lee admin:publish --link
```

也可以手动操作：

```bash
# 符号链接（开发环境）
ln -s vendor/watsonhaw/lychee-admin/asset public/admin

# 复制（生产环境）
cp -r vendor/watsonhaw/lychee-admin/asset/* public/admin/
```

生产环境也可通过 Nginx 将 `lychee-admin/asset/` 直接映射到 `/admin/` 路径。

## 数据库表

迁移创建以下四张表：

- `admin` — 管理员
- `admin_role` — 角色
- `admin_menu` — 菜单
- `admin_permission` — 权限

具体字段定义见 `lychee-admin/database/migrations/` 目录下的迁移文件。
