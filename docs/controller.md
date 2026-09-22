# 控制器 Controller

控制器负责接收请求、调用业务逻辑、返回响应。框架不强制控制器继承基类，
开发者可按需选择：

- **需要 CRUD 的控制器**：继承 `Lychee\routing\ResourceController`，获得请求注入、统一响应、验证快捷方式和零代码 CRUD 能力
- **不需要 CRUD 但想便捷响应**：继承 `Lychee\http\Controller`，获得请求注入与 `success()` / `fail()` / `paginate()` 响应快捷方法
- **完全自定义**：直接写普通类，使用全局助手 `success()` / `fail()` / `json()` 返回响应

## 资源控制器 ResourceController

继承 `Lychee\routing\ResourceController` 可实现零代码 CRUD，同时获得请求注入、`initialize()` 生命周期钩子、统一响应与验证快捷方式。
配合 `#[Resource]` 路由注解使用，子类只需声明模型与验证器。

```php
namespace App\controller;

use App\model\User;
use App\validate\UserValidate;
use Lychee\routing\Resource;
use Lychee\routing\ResourceController;

#[Resource('/users')]
class UserController extends ResourceController
{
    protected string $modelClass = User::class;
    protected string $validateClass = UserValidate::class;
}
```

以上代码自动提供以下接口：

| 方法 | 路由 | 说明 |
| --- | --- | --- |
| `index()` | `GET /users` | 列表（分页 + 查询 DSL） |
| `save(Request $request)` | `POST /users` | 新建（验证 + 唯一校验） |
| `read($id)` | `GET /users/{id}` | 详情 |
| `update(Request $request, $id)` | `PUT /users/{id}` | 更新 |
| `delete($id)` | `DELETE /users/{id}` | 删除 |
| `batch_delete(Request $request)` | `DELETE /users` | 批量删除 |

### 依赖注入

基类构造函数已注入容器与请求，子类无需重写构造函数。若需要其他依赖，可通过方法参数注入：

```php
use App\model\User;
use Lychee\http\JsonResponse;

class UserController extends ResourceController
{
    public function index(User $user): JsonResponse
    {
        // $this->request 已自动注入
        return $this->success($user->select());
    }
}
```

### 方法参数自动注入请求参数

控制器方法的参数名与请求参数同名时，框架会自动从请求中取值并注入（支持类型自动转换）。
路由参数优先级最高，其次是 GET 查询参数和 POST 请求体参数。

```php
class UserController extends ResourceController
{
    // GET /users?page=2&page_size=20
    public function index(int $page = 1, int $pageSize = 10): JsonResponse
    {
        // $page = 2, $pageSize = 20
        return $this->success(['page' => $page, 'pageSize' => $pageSize]);
    }

    // GET /users/{id}?include=profile
    public function read(int $id, string $include = ''): JsonResponse
    {
        // $id 来自路由参数，$include 来自查询参数
        return $this->success(['id' => $id, 'include' => $include]);
    }

    // POST /users  { "name": "Alice", "age": 30 }
    public function save(string $name, int $age = 0): JsonResponse
    {
        // $name, $age 自动从请求体注入
        return $this->success(compact('name', 'age'));
    }
}
```

> 若参数在请求中不存在且无默认值、不允许 `null`，内核会抛出 `RuntimeException`。
> 建议给可选参数设置默认值。

### 生命周期钩子 `initialize()`

`initialize()` 在中间件执行完毕后、方法调用前由内核自动调用。
子类覆盖此方法做初始化，**无需调用 `parent::initialize()`**。

```php
class UserController extends ResourceController
{
    protected array $config = [];

    protected function initialize(): void
    {
        $this->config = config('user', []);
    }
}
```

> 由于 `initialize()` 在中间件之后执行，认证、i18n、session 等中间件对请求的修改在此均已生效。

### 统一响应

基类提供 `success()` / `fail()` / `paginate()` 三个 JSON 响应快捷方法，
默认格式为 `{errno, code, msg, data}`。如需自定义格式，覆盖对应方法即可。

```php
// 成功响应
return $this->success($data);
return $this->success($data, '创建成功', 201);

// 失败响应
return $this->fail('参数错误');
return $this->fail('未找到', 404);

// 分页响应（资源控制器版本，第一个参数必须是 Query / Model）
return $this->paginate($query, $current, $pageSize);
```

`paginate()` 的分页数据统一放在 `data.list`，总数放在 `data.total`：

```json
{
  "errno": 0,
  "code": 200,
  "msg": "success",
  "data": {
    "list": [{ "id": 1 }, { "id": 2 }],
    "total": 56
  }
}
```

> `ResourceController` 重写了 `paginate()`：只接受 `Query` / `Model`，内部调用 think-orm
> 的分页器，供 `index()` 等 CRUD 方法使用。基础控制器 `Lychee\http\Controller` 提供的
> 通用版本还支持直接传入数组，详见下文[基础控制器](#基础控制器-controller)章节。

#### 非 JSON 响应

`success()` / `fail()` 仅用于 JSON。若需返回 HTML、下载或重定向，
直接返回对应的响应对象，内核会原样输出：

```php
// HTML 视图
return view('user/index', ['list' => $list]);

// 文件下载
return download('report.pdf');

// 重定向
return redirect('/login');
```

#### 全局助手函数

未继承 `ResourceController` 的控制器或非控制器场景（如中间件）可使用全局助手：

```php
return success($data);
return fail('错误', 400);
return json(['custom' => 'structure']);
```

### 验证快捷方式

基类的 `validate()` 方法封装了验证器调用，失败时抛出 `ValidateException`。

```php
// 传入验证器类名
$this->validate($request->post(), UserValidate::class);

// 支持场景语法 "类名.场景"
$this->validate($request->post(), 'User.save');

// 直接传入规则数组
$this->validate($data, [
    'name'  => 'require|max:25',
    'email' => 'require|email',
]);
```

### 模型自动推断

若未声明 `$modelClass`，框架按控制器名自动推断：
`UserController` → `app\model\User`，`OrderController` → `app\model\Order`。

### 查询 DSL

`index()` 方法支持通过查询参数后缀构造复杂条件：

| 后缀 | 说明 | 示例 |
| --- | --- | --- |
| `_like` | 模糊匹配 | `?name_like=张` → `name LIKE '%张%'` |
| `_range` | 时间范围 | `?create_time_range[]=2024-01-01&create_time_range[]=2024-12-31` |
| `_between` | 数值范围 | `?age_between[]=18&age_between[]=60` |
| `_in` | 集合匹配 | `?status_in=1,2,3` |
| 无后缀 | 精确匹配 | `?status=1` → `status = 1` |

> `_in` 查询会自动识别模型的 JSON 数组字段，使用 `JSON_CONTAINS` 匹配。

### 唯一校验

声明 `$uniqueFields` 后，`save()` / `update()` 会自动检查唯一性，支持联合唯一：

```php
class UserController extends ResourceController
{
    protected string $modelClass = User::class;

    // 邮箱唯一
    protected array $uniqueFields = ['email'];

    // 联合唯一：同一租户下用户名唯一
    // protected array $uniqueFields = ['tenant_id', 'username'];
}
```

### 列表搜索与排序（零代码配置）

继承 `ResourceController` 后，`index()` 和 `read()` 无需重写即可自动支持搜索、排序、关联预加载与访问器追加，通过属性配置：

```php
class UserController extends ResourceController
{
    protected string $modelClass = User::class;

    /** 搜索字段白名单：只允许这些字段作为查询条件（支持 _like 等后缀 DSL） */
    protected array $searchFields = ['username_like', 'status'];

    /** 分页参数名：[当前页, 每页条数]，默认 ['current', 'pageSize'] */
    protected array $pageFields = ['page', 'limit'];

    /** 关联预加载，index 和 read 均生效 */
    protected array $with = ['profile', 'roles'];

    /** 追加访问器属性，index 和 read 均生效 */
    protected array $append = ['nickname', 'is_vip'];

    /** 默认排序，请求未传 order 时使用 */
    protected array $order = ['create_time' => 'desc'];
}
```

- `searchFields`：白名单内的字段才会从 GET 参数中提取为查询条件，避免前端传任意字段触发异常查询；为空（默认）时取全部 GET 参数。
- `pageFields`：分页参数名，默认 `['current', 'pageSize']`，可自定义为 `['page', 'limit']` 等。
- `with`：预加载关联，`index`（列表）和 `read`（详情）均生效。
- `append`：追加访问器属性，`index` 和 `read` 均生效。
- `order`：列表默认排序，请求传了 `order` 参数时以请求为准。

> 若 `index` 与 `read` 需要不同的 `with`/`append`，重写对应方法并显式传入参数即可（`baseIndex` / `baseRead` 均接受 `$append` 和 `$with` 参数，优先级高于属性）。

请求示例：

```
GET /api/users?username_like=张&status=1&current=1&pageSize=10
```

### 自定义方法与 base* 复用

需要自定义逻辑时，可覆盖对应方法。框架提供了 `baseIndex` / `baseSave` / `baseRead` / `baseUpdate` / `baseDelete` / `baseBatchDelete` 六个 protected 方法，
覆盖后仍可调用它们复用分页、排序、验证、数据权限等逻辑。

#### 自定义查询条件（index）

```php
class UserController extends ResourceController
{
    protected string $modelClass = User::class;

    public function index(int $current = 1, int $pageSize = 20, ?string $name = null, ?int $status = null): JsonResponse
    {
        $where = [];
        if ($name !== null) {
            $where['name_like'] = $name;  // applyWhere 会自动加 %，无需手写
        }
        if ($status !== null) {
            $where['status'] = $status;
        }

        return $this->baseIndex($where, $current, $pageSize);
    }
}
```

`baseIndex()` 签名：

```php
protected function baseIndex(
    array $where = [],                       // 查询条件（支持 _like / _range / _between / _in 后缀）
    int $current = 1,                        // 当前页码（调用方显式传入）
    int $pageSize = 20,                      // 每页条数（调用方显式传入）
    array $append = [],                      // 追加属性
    array $with = [],                        // 关联预加载
): JsonResponse
```

> 分页参数 `current` / `pageSize` 需由调用方显式传入；排序优先从请求 `order` 参数读取，未传或为空时使用 `$this->order` 属性（默认 `['create_time' => 'desc']`）。子类可覆盖该属性自定义默认排序。

#### 自定义新建/更新

```php
class UserController extends ResourceController
{
    protected string $modelClass = User::class;

    // 请求体即为落库数据时，直接复用 baseSave / baseUpdate
    public function save(Request $request): JsonResponse
    {
        return $this->baseSave($request);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->baseUpdate($request, $id);
    }

    // 需要补充字段（如 created_by）时，直接操作模型
    public function saveWithAudit(Request $request): JsonResponse
    {
        $data                = $request->post();
        $data['created_by']  = request()->loginId();

        $model = $this->getModelClass();
        return $this->success($model->create($data));
    }
}
```

#### 直接重写（不复用 base 方法）

```php
class UserController extends ResourceController
{
    protected string $modelClass = User::class;

    public function read(int $id): JsonResponse
    {
        $user = $this->getModel()->with('profile')->find($id);

        if (!$user) {
            return $this->fail($this->notExistMessage);
        }

        return $this->success($user);
    }
}
```

## 基础控制器 Controller

继承 `Lychee\http\Controller` 可获得请求注入、`initialize()` 生命周期钩子与统一 JSON 响应快捷方法，
适用于不需要 CRUD、但希望使用 `$this->request` 和 `$this->success()` / `$this->fail()` / `$this->paginate()` 的场景。

```php
namespace App\controller;

use Lychee\http\Controller;
use Lychee\http\JsonResponse;
use Lychee\routing\Route;

#[Route('/upload')]
class UploadController extends Controller
{
    public function index(): JsonResponse
    {
        $file = $this->request->file('file');

        return $this->success(['name' => $file?->getOriginalName()]);
    }
}
```

### 提供的能力

| 成员 | 说明 |
| --- | --- |
| `$this->app` | 容器实例 |
| `$this->request` | 请求实例 |
| `initialize()` | 初始化钩子，在中间件之后、方法调用前执行 |
| `$this->success($data, $msg, $code)` | 成功 JSON 响应 |
| `$this->fail($msg, $code)` | 失败 JSON 响应 |
| `$this->paginate($items, $page, $pageSize, $message, $httpStatus)` | 分页 JSON 响应 |

> `Lychee\routing\ResourceController` 继承自此基类，因此资源控制器同样拥有以上能力。

### 分页响应 `paginate()`

`paginate()` 在传入查询构建器时自动完成总数统计与分页查询，也可以直接传入一个数组作为当页数据。

方法签名：

```php
protected function paginate(
    mixed  $items = [],       // Query、Model 或当页数据数组
    int    $page = 1,         // 当前页码
    int    $pageSize = 10,    // 每页条数，自动限制在 1~200
    string $message = 'success',
    int    $httpStatus = 200,
): JsonResponse
```

传入 `Query` / `Model` 时，框架会先执行 `count()` 统计总数，再查询当前页数据；
传入数组时数组原样作为当页数据，`total` 为 0。非数组（如 `null`、字符串）会被归一为空数组。

```php
use App\model\Order;
use think\facade\Db;

class OrderController extends Controller
{
    // 传入 Query：自动 count + 分页
    public function list(): JsonResponse
    {
        $page     = (int) $this->request->param('page', 1);
        $pageSize = (int) $this->request->param('pageSize', 10);

        $query = Db::table('orders')->where('status', 1);

        return $this->paginate($query, $page, $pageSize);
    }

    // 传入 Query（由模型产生）：Model::where() 静态调用返回的是 Query
    public function vipList(): JsonResponse
    {
        return $this->paginate(
            Order::where('is_vip', 1),
            page: 1,
            pageSize: 20,
            message: 'ok',
            httpStatus: 200,
        );
    }

    // 传入 Model 实例：框架自动调用其 db() 获取查询构建器（不带额外条件）
    public function allList(): JsonResponse
    {
        return $this->paginate(new Order(), 1, 20);
    }

    // 传入数组：数据来自外部接口或自行组装时，直接作为当页数据
    public function remoteList(): JsonResponse
    {
        $items = $this->fetchFromRemote();

        return $this->paginate($items);
    }
}
```

响应结构（分页数据在 `data.list`，总数在 `data.total`）：

```json
{
  "errno": 0,
  "code": 200,
  "msg": "success",
  "data": {
    "list": [{ "id": 3 }, { "id": 4 }],
    "total": 5
  }
}
```

> 与 `ResourceController::paginate()` 的区别：基础控制器版本接受任意 `Query` / `Model` / 数组，
> 自行通过 `count()` + `page()` 完成分页；资源控制器的重写版本只接受 `Query` / `Model`，
> 内部使用 think-orm 分页器。两者响应结构一致。

## 不继承基类的控制器

如果控制器不需要 CRUD，也不需要基类提供的便捷能力，可以不继承任何基类，直接使用全局助手返回响应：

```php
namespace App\controller;

use Lychee\http\JsonResponse;
use Lychee\http\Request;

class HomeController
{
    public function index(): JsonResponse
    {
        return success(['message' => 'hello']);
    }

    public function upload(Request $request): JsonResponse
    {
        $file = $request->file('file');
        // ... 处理上传
        return success(['url' => $url]);
    }
}
```

> 内核通过 `method_exists` 检测 `initialize()` 方法，不继承基类的控制器同样可以定义 `initialize()` 钩子。

## 数据权限 HasDataPermission

`Lychee\routing\HasDataPermission` 是一个可选 Trait，为资源控制器提供数据范围过滤。
`use` 后会覆盖 `applyDataPermission()`，按当前登录用户的数据权限过滤查询。

```php
namespace App\controller;

use App\model\Order;
use Lychee\routing\HasDataPermission;
use Lychee\routing\Resource;
use Lychee\routing\ResourceController;

#[Resource('/orders')]
class OrderController extends ResourceController
{
    use HasDataPermission;

    protected string $modelClass = Order::class;

    protected array $dataPermission = [
        'enabled'     => true,
        'userIdField' => 'user_id',
        'module'      => 'order',
    ];
}
```

### 工作原理

- 从 `request()->loginId()` 获取当前登录用户 ID（由认证中间件挂载）
- 调用 `isAdmin($userId)` 判断是否管理员（默认返回 `false`）
- 调用 `getPermissionLevel($userId, $module)` 获取权限级别（默认返回 `'self'`）
- `'self'` 级别：自动附加 `where(user_id, 当前用户ID)` 条件
- `'all'` 级别：不附加过滤

### 覆盖管理员与权限逻辑

Trait 默认采用最保守策略。实际项目中需覆盖 `isAdmin()` 和 `getPermissionLevel()`：

```php
class OrderController extends ResourceController
{
    use HasDataPermission;

    protected array $dataPermission = [
        'enabled'     => true,
        'userIdField' => 'user_id',
        'module'      => 'order',
    ];

    protected function isAdmin(int $userId): bool
    {
        return User::find($userId)?->is_admin ?? false;
    }

    protected function getPermissionLevel(int $userId, string $module): string
    {
        $role = User::find($userId)?->role;
        if (!$role || empty($role->data_scope)) {
            return 'self';
        }

        $dataScope = is_array($role->data_scope)
            ? $role->data_scope
            : json_decode($role->data_scope, true);

        return $dataScope[$module] ?? 'self';
    }
}
```

### 单条数据权限检查

`canAccessData($ownerId)` 用于在非查询场景（如更新、删除前）判断当前用户是否有权操作某条数据：

```php
if (!$this->canAccessData($order->user_id)) {
    return $this->fail('无权操作', 403);
}
```

## 多租户 HasTenant

`Lychee\routing\HasTenant` 是一个可选 Trait，基于表字段 `tenant_id` 实现共享数据库 + 共享表的多租户隔离。
`use` 后会覆盖 `applyTenantScope()` 与 `fillTenantId()`，自动在查询时过滤当前租户数据、在新建/更新时写入 `tenant_id`。

```php
namespace App\controller;

use App\model\Order;
use Lychee\routing\HasTenant;
use Lychee\routing\Resource;
use Lychee\routing\ResourceController;

#[Resource('/orders')]
class OrderController extends ResourceController
{
    use HasTenant;

    protected string $modelClass = Order::class;

    protected array $tenantConfig = [
        'enabled'        => true,
        'tenantIdField'  => 'tenant_id',
        'autoFill'       => true,
        'bypassForAdmin' => true,
    ];

    protected function getTenantId(): ?int
    {
        // 从当前登录用户获取租户 ID
        return User::find(request()->loginId())?->tenant_id;
    }
}
```

### 工作原理

- **查询隔离**：`baseIndex` / `baseRead` / `baseUpdate` / `baseDelete` / `baseBatchDelete` 中的查询会自动附加 `where(tenant_id, 当前租户ID)`
- **写入填充**：`baseSave` / `baseUpdate` 会自动在数据中填入 `tenant_id`（若未显式指定）
- 从 `getTenantId()` 获取当前租户 ID（需子类覆盖）
- 平台管理员可通过 `isAdmin()` 判断跳过隔离（由 `bypassForAdmin` 控制）

### 配置项

| 配置 | 默认值 | 说明 |
| --- | --- | --- |
| `enabled` | `true` | 是否开启租户隔离 |
| `tenantIdField` | `'tenant_id'` | 租户 ID 字段名 |
| `autoFill` | `true` | 新建/更新时是否自动填充 `tenant_id` |
| `bypassForAdmin` | `true` | 平台管理员是否跳过租户隔离 |

### 覆盖管理员逻辑

`isAdmin(int $userId)` 默认返回 `false`，实际项目中需覆盖：

```php
class OrderController extends ResourceController
{
    use HasTenant;

    protected function getTenantId(): ?int
    {
        return User::find(request()->loginId())?->tenant_id;
    }

    protected function isAdmin(int $userId): bool
    {
        return User::find($userId)?->is_platform_admin ?? false;
    }
}
```

### 单条数据归属检查

`isTenantData($dataTenantId)` 用于判断某条数据是否属于当前租户：

```php
if (!$this->isTenantData($order->tenant_id)) {
    return $this->fail('无权操作', 403);
}
```

### 与 HasDataPermission 同时使用

`HasTenant` 与 `HasDataPermission` 可以同时 `use`，两者分别覆盖不同的钩子，互不冲突。

但两者都定义了 `isAdmin(int $userId): bool` 方法，同时使用时需在控制器类中覆盖 `isAdmin()` 以解决 trait 方法冲突（类方法优先于 trait 方法）：

```php
class OrderController extends ResourceController
{
    use HasDataPermission, HasTenant;

    protected string $modelClass = Order::class;

    protected function getTenantId(): ?int
    {
        return User::find(request()->loginId())?->tenant_id;
    }

    // 覆盖 isAdmin，同时满足两个 trait 的需求
    protected function isAdmin(int $userId): bool
    {
        return User::find($userId)?->is_platform_admin ?? false;
    }
}
```
