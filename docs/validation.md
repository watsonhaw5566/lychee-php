# 数据验证 Validation

框架内置轻量校验器 `Lychee\validate\Validate`，提供与 think-validate 兼容的常用 API（`rule / message / batch / scene / failException / extend / check / getError` 等），零外部依赖。

验证失败时抛出 `Lychee\validate\ValidateException`，HTTP 内核会自动捕获并返回 **400** 状态码，`msg` 字段取自校验器的实际错误信息：

```json
{
  "code": 400,
  "msg": "用户名必填；邮箱格式不正确"
}
```

`Lychee\validate\ValidateException` 的构造函数接受 `$error`（字符串或数组）和可选的 `$key`（字段名）：

```php
// 单个错误
throw new \Lychee\validate\ValidateException('用户名必填');

// 批量错误（数组形式）
throw new \Lychee\validate\ValidateException(['name' => '用户名必填', 'email' => '邮箱格式不正确']);
```

> 如需完全自定义响应结构，可在控制器中 `try/catch` 捕获 `ValidateException`，自行返回 `JsonResponse`；或通过 `app.exception_handler` 配置自定义异常处理器（详见 [应用配置 App](./app)）。

## 链式调用（控制器内联验证）

```php
use Lychee\http\Request;
use Lychee\validate\Validate;
use Lychee\validate\ValidateException;

public function save(Request $request): JsonResponse
{
    $data = $request->param();

    $validate = new Validate();
    $validate->rule([
        'name'  => 'require|max:25',
        'email' => 'require|email',
        'age'   => 'number',
    ])->message([
        'name.require'  => '用户名必填',
        'name.max'      => '用户名不超过25个字符',
        'email.require' => '邮箱必填',
        'email.email'   => '邮箱格式不正确',
    ]);

    if (!$validate->check($data)) {
        /** @var array<string,string> $errors */
        $errors = (array) $validate->getError(true);

        throw new ValidateException($errors);
    }

    // 验证通过，继续业务逻辑...
}
```

`getError(true)` 始终返回 `字段 => 错误信息` 的关联数组；不传参数时，若只有一个错误则返回字符串，否则返回数组。

## 验证器类

把规则集中到独立的验证器类中，便于复用。继承 `Lychee\validate\Validate`，在 `$rule`、`$message` 属性中定义规则与提示：

```php
// app/validate/User.php
namespace app\validate;

use Lychee\validate\Validate;

class User extends Validate
{
    protected array $rule = [
        'name'  => 'require|max:25',
        'age'   => 'number|between:1,120',
        'email' => 'require|email',
    ];

    protected array $message = [
        'name.require' => '名称必须',
        'name.max'     => '名称最多不能超过25个字符',
        'age.number'   => '年龄必须是数字',
        'age.between'  => '年龄只能在1-120之间',
        'email.email'  => '邮箱格式错误',
    ];
}
```

调用：

```php
$validate = new \App\validate\User();

if (!$validate->check($data)) {
    throw new ValidateException((array) $validate->getError(true));
}
```

## validate() 助手函数

框架提供全局 `validate()` 函数，返回一个 `Lychee\validate\Validate` 实例。默认开启 `failException`，验证失败会直接抛出 `Lychee\validate\ValidateException`，HTTP 内核自动捕获并返回 **400** 状态码；如需手动处理，可关闭：

```php
// 传入规则数组，直接验证（失败抛异常）
validate([
    'name'  => 'require|max:25',
    'email' => 'email',
])->check($data);

// 关闭自动抛异常，自行判断
$v = validate()->failException(false);
$v->rule(['name' => 'require']);
if (!$v->check($data)) {
    $errors = (array) $v->getError(true);
}
```

## 批量验证

默认遇到第一个错误即停止。调用 `batch(true)` 可一次性返回所有字段的错误：

```php
$validate = new Validate();
$validate->rule([
    'name'  => 'require',
    'email' => 'require|email',
])->batch(true);

$validate->check($data);
$errors = (array) $validate->getError(true); // 包含所有失败字段
```

## 验证场景

同一验证器可按场景限定要验证的字段。在验证器类中定义 `$scene` 属性或 `sceneXxx` 方法：

```php
class User extends Validate
{
    protected array $rule = [
        'name'  => 'require|max:25',
        'email' => 'require|email',
        'age'   => 'number',
    ];

    protected array $scene = [
        'edit'  => ['name', 'age'],   // 编辑时只验证 name、age
        'login' => ['email'],          // 登录时只验证 email
    ];

    // 也可用方法形式：$this->only() / remove() / append() 自由控制
    public function sceneRegister(): void
    {
        $this->only(['name', 'email'])
            ->append('email', 'confirm');
    }
}

// 调用时指定场景
$validate = new \App\validate\User();
$validate->scene('edit')->check($data);
```

## 自定义验证规则

通过 `extend()` 注册实例级自定义规则。回调签名 `fn ($value, $param, $data, $field): bool|string`：

```php
$validate = new Validate();
$validate->extend('checkName', function ($value, $param, $data, $field) {
    return $value === 'admin' ? '用户名不能为 admin' : true;
}, '用户名非法');

$validate->rule(['name' => 'require|checkName'])->check($data);
```

也可以在验证器子类中用 `checkXxx` 方法扩展（与 ThinkPHP 习惯一致），规则调度顺序为 `extend` > 内置 `Rule` > 子类 `checkXxx` 方法：

```php
class User extends Validate
{
    protected array $rule = ['name' => 'require|notAdmin'];

    protected function checkNotAdmin(mixed $value, array $params, array $data, string $field): bool|string
    {
        return $value !== 'admin' ? true : '用户名不能为 admin';
    }
}
```

## 默认语言

通过 `useZh()` / `useEn()` 切换内置错误提示语言，默认 `en`。可通过 `Validate::maker()` 为所有实例注册构造钩子（框架启动时已根据 `app.lang` 自动调用）：

```php
Validate::maker(fn (Validate $v) => $v->useZh());
```

## 常用验证规则速查

| 规则 | 说明 | 示例 |
| --- | --- | --- |
| `require` | 必须 | `'name' => 'require'` |
| `number` / `integer` | 数字 / 整数 | `'age' => 'number'` |
| `float` | 浮点数 | `'price' => 'float'` |
| `boolean` | 布尔值 | `'status' => 'boolean'` |
| `email` | 邮箱格式 | `'email' => 'email'` |
| `mobile` | 手机号 | `'phone' => 'mobile'` |
| `url` | URL 格式 | `'link' => 'url'` |
| `ip` | IP 地址 | `'ip' => 'ip'` |
| `date` | 日期格式 | `'birth' => 'date'` |
| `alpha` / `alphaNum` | 字母 / 字母数字 | `'code' => 'alphaNum'` |
| `in` / `notIn` | 在 / 不在枚举范围 | `'type' => 'in:1,2,3'` |
| `between` / `notBetween` | 数值区间 | `'age' => 'between:1,120'` |
| `length` | 长度（字符串或数组） | `'name' => 'length:2,10'` |
| `max` / `min` | 最大 / 最小长度 | `'name' => 'max:25'` |
| `gt` / `lt` / `egt` / `elt` | 大于 / 小于 / 大于等于 / 小于等于 | `'num' => 'gt:0'` |
| `eq` / `same` | 等于指定值 | `'status' => 'eq:1'` |
| `confirm` | 与某字段值一致 | `'repassword' => 'confirm:password'` |
| `different` | 与某字段不同 | `'new' => 'different:old'` |
| `regex` | 正则匹配 | `'code' => 'regex:/^\d{4}$/'` |
| `accepted` / `declined` | 接受 / 拒绝值 | `'agree' => 'accepted'` |
| `array` | 数组 | `'tags' => 'array'` |
| `file` / `image` | 文件 / 图片 | `'avatar' => 'image'` |
| `fileExt` / `fileMime` / `fileSize` | 文件扩展名 / MIME / 大小上限 | `'doc' => 'fileExt:pdf,doc'` |

多个规则用 `|` 分隔，也可写成数组形式：

```php
$validate->rule([
    'name' => ['require', 'max' => 25],
    'age'  => ['number', 'between' => '1,120'],
]);
```

除 `require` 外的所有规则默认视为可选：当字段未提供或值为 `null`/`''` 时自动跳过。要求字段必填请显式加 `require`。
