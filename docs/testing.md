# 测试 Testing

框架提供 `Lychee\testing\LycheeTestCase` 测试基类，封装应用初始化、请求模拟与数据库事务回滚，降低编写功能测试的复杂度。

## 安装

确保项目已安装 PHPUnit：

```bash
composer require --dev phpunit/phpunit
```

## 基本用法

```php
<?php

namespace tests;

use Lychee\testing\LycheeTestCase;

class UserControllerTest extends LycheeTestCase
{
    /** 应用根目录（包含 app/、config/ 等） */
    protected string $basePath = __DIR__ . '/../';

    protected function setUp(): void
    {
        parent::setUp();
        // 建表等 DDL 在此执行（sqlite 无 DDL 回滚问题）
        $this->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name VARCHAR(255))');
    }

    public function test_index(): void
    {
        $resp = $this->get('/users');
        $this->assertSame(200, $resp->status);
    }
}
```

## 核心能力

| 能力 | 说明 |
| --- | --- |
| 应用初始化 | `setUp` 中自动创建 Application、Kernel、Db |
| 事务回滚 | 每个测试自动开启事务 → 结束回滚，数据互不影响 |
| 请求模拟 | `get` / `post` / `put` / `patch` / `delete` |
| JSON 解析 | `$this->json($response)` 直接拿到数组 |
| 登录模拟 | `$this->actingAs($userId)` 模拟登录态 |
| 容器解析 | `$this->make(Service::class)` 获取带依赖注入的实例 |

## 可用属性

| 属性 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `$basePath` | `string` | `''` | 应用根目录，子类必须设置 |
| `$controllerNamespace` | `string` | `'app\\controller'` | 控制器命名空间 |
| `$useTransactions` | `bool` | `true` | 是否启用事务回滚 |
| `$app` | `Application` | — | 应用实例 |
| `$kernel` | `Kernel` | — | HTTP 内核 |
| `$db` | `DbManager` | — | 数据库管理器 |

## 请求模拟

```php
// GET
$resp = $this->get('/users', ['name' => '张']);

// POST（JSON 或 form 都会自动解析）
$resp = $this->post('/users', ['name' => 'Alice']);

// PUT / PATCH / DELETE
$resp = $this->put('/users/1', ['name' => 'Bob']);
$resp = $this->delete('/users/1');
```

### 解析 JSON 响应

```php
$resp = $this->get('/users/1');
$data = $this->json($resp);
// ['errno' => 0, 'code' => 200, 'msg' => 'success', 'data' => [...]]
```

### 模拟登录

```php
$resp = $this->actingAs(1)->get('/users/profile');
```

`actingAs` 会在请求中注入登录用户 ID，控制器内通过 `currentId()` / `$request->loginId` 读取。

## Service 测试

不经过 HTTP，直接测试业务逻辑：

```php
use app\service\CustomerService;

class CustomerServiceTest extends LycheeTestCase
{
    protected string $basePath = __DIR__ . '/../';

    public function test_login(): void
    {
        $service = $this->make(CustomerService::class);
        $ret = $service->generateRandomNick();
        $this->assertNotEmpty($ret);
    }
}
```

- `$this->make()` 从容器解析，支持构造函数依赖注入
- Service 内部的 `app()`、`config()`、模型查询等均正常工作
- 数据库操作同样享受事务回滚

> 纯逻辑 Service（无 DB 依赖）可直接继承 PHPUnit 的 `TestCase`，更轻量。

## 事务回滚说明

每个测试方法执行前开启事务，结束后回滚，保证测试间数据隔离。

- **sqlite**：DDL（建表）也可回滚，直接在 `setUp` 中建表即可
- **MySQL**：DDL 会隐式提交事务，导致回滚失效。建议把建表放在 `parent::setUp()` 之前，或设置 `protected bool $useTransactions = false`

## 完整示例

```php
<?php

namespace tests\controller;

use Lychee\testing\LycheeTestCase;

class UserControllerTest extends LycheeTestCase
{
    protected string $basePath = __DIR__ . '/../../';

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL
        )');
    }

    public function test_store_and_read(): void
    {
        // 创建
        $resp = $this->post('/users', ['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->assertSame(200, $resp->status);

        // 读取
        $resp = $this->get('/users/1');
        $data = $this->json($resp);
        $this->assertSame('Alice', $data['data']['name']);
    }

    public function test_index_with_filter(): void
    {
        $this->post('/users', ['name' => '张三', 'email' => 'zs@example.com']);
        $this->post('/users', ['name' => '李四', 'email' => 'ls@example.com']);

        $resp = $this->get('/users', ['name_like' => '张']);
        $data = $this->json($resp);
        $this->assertSame(1, $data['data']['total']);
    }
}
```
