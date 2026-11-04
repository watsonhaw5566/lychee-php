# HTTP 客户端 HttpClient

框架内置轻量级 HTTP 客户端，基于 cURL 封装，零外部依赖，无需安装 Guzzle 即可发起外部 HTTP 请求。

## 快速开始

最简单的方式是使用静态快捷方法：

```php
use Lychee\http\client\HttpClient;

// GET 请求
$response = HttpClient::get('https://api.example.com/users');
$users = $response->json();

// POST 请求（默认 JSON 格式）
$response = HttpClient::post('https://api.example.com/users', [
    'name'  => '张三',
    'email' => 'zhangsan@example.com',
]);
```

## 助手函数

全局 `http()` 助手返回一个链式构建器，支持灵活配置：

```php
$response = http()->get('https://api.example.com/users');

$response = http()
    ->withToken('your-api-token')
    ->timeout(10)
    ->post('https://api.example.com/users', ['name' => '张三']);
```

## 链式配置

使用 `HttpClient::create()` 或 `http()` 获取构建器，通过链式调用配置请求参数：

```php
$response = HttpClient::create()
    ->baseUrl('https://api.example.com')
    ->withToken('your-token')
    ->withHeaders(['X-App-Id' => 'myapp'])
    ->timeout(15)
    ->retry(3, 200)
    ->get('/users', ['page' => 1]);
```

## 请求方法

支持常见的 HTTP 方法：

```php
$client = http()->baseUrl('https://api.example.com');

$client->get('/users', ['page' => 1]);          // GET + 查询参数
$client->post('/users', ['name' => '张三']);      // POST
$client->put('/users/1', ['name' => '李四']);     // PUT
$client->patch('/users/1', ['status' => 1]);     // PATCH
$client->delete('/users/1');                     // DELETE
$client->head('/users');                         // HEAD
```

## 请求体格式

默认以 JSON 格式发送请求体，可切换为表单或 multipart：

```php
// JSON（默认）
http()->post('/api/data', ['key' => 'value']);

// application/x-www-form-urlencoded
http()->asForm()->post('/login', [
    'username' => 'admin',
    'password' => '123456',
]);

// multipart/form-data（文件上传）
http()->asMultipart()->post('/upload', [
    'file' => new CURLFile('/path/to/file.jpg'),
    'name' => 'avatar',
]);
```

## 认证

```php
// Bearer Token
http()->withToken('your-api-token')->get('/me');

// 自定义 Token 类型
http()->withToken('your-token', 'Token')->get('/me');

// Basic Auth
http()->withBasicAuth('username', 'password')->get('/me');
```

## 请求头

```php
// 批量设置
http()->withHeaders([
    'X-App-Id'  => 'myapp',
    'X-Version' => '2.0',
])->get('/api');

// 单个设置
http()->withHeader('Accept-Language', 'zh-CN')->get('/api');
```

## 查询参数

`withQuery()` 设置的参数会附加到所有请求上，`get()` 的第二参数会与之合并：

```php
$client = http()
    ->baseUrl('https://api.example.com')
    ->withQuery(['token' => 'xxx']);

// 最终 URL: https://api.example.com/users?token=xxx&page=1
$client->get('/users', ['page' => 1]);
```

## 超时控制

```php
http()
    ->timeout(30)           // 总超时 30 秒（默认）
    ->connectTimeout(5)     // 连接超时 5 秒
    ->get('https://slow-api.example.com/data');
```

## 失败重试

对 5xx 错误和 429（Too Many Requests）自动重试：

```php
$response = http()
    ->retry(3, 200)   // 最多重试 3 次，每次间隔 200ms
    ->get('https://unstable-api.example.com/data');
```

## SSL 验证

生产环境默认开启 SSL 证书验证。开发/测试环境可临时关闭：

```php
http()->withoutVerifying()->get('https://self-signed.example.com/api');
```

> 不要在生产环境关闭 SSL 验证。

## 读取响应

```php
$response = HttpClient::get('https://api.example.com/users');

// 状态码
$response->status();       // 200

// 响应体
$response->body();         // 原始字符串
$response->json();         // 解码为数组
$response->json('data');   // 提取指定字段
$response->json('missing', '默认值');  // 支持默认值

// 响应头（不区分大小写）
$response->header('Content-Type');     // "application/json"
$response->headers();                  // 全部响应头

// 字符串转换
echo $response;            // 等同于 $response->body()
```

## 状态判断

```php
$response->ok();           // 2xx
$response->successful();   // 同 ok()
$response->failed();       // 4xx 或 5xx
$response->clientError();  // 4xx
$response->serverError();  // 5xx
```

## 错误处理

默认不会在请求失败时抛异常。调用 `throw()` 可在 4xx/5xx 时抛出 `RequestException`：

```php
use Lychee\http\client\RequestException;

try {
    $response = HttpClient::get('https://api.example.com/not-found');
    $response->throw();  // 404 时抛出异常
} catch (RequestException $e) {
    $e->getCode();       // HTTP 状态码（如 404）
    $e->getMessage();    // "HTTP request returned status code 404"
    $e->getResponse();   // 获取原始 ClientResponse 对象
}
```

成功时 `throw()` 返回自身，可以链式使用：

```php
$data = HttpClient::get('https://api.example.com/users')
    ->throw()
    ->json();
```

cURL 连接错误（DNS 解析失败、连接超时等）会直接抛出 `RequestException`，无需调用 `throw()`。

## 完整示例

```php
use Lychee\http\client\HttpClient;
use Lychee\http\client\RequestException;

// 调用第三方支付接口
$response = HttpClient::create()
    ->baseUrl('https://pay.example.com/v2')
    ->withToken(config('pay.secret_key'))
    ->timeout(10)
    ->retry(2, 500)
    ->post('/orders', [
        'amount'   => 9900,
        'currency' => 'CNY',
        'subject'  => '商品购买',
    ]);

if ($response->failed()) {
    logger()->error('支付创建失败', [
        'status' => $response->status(),
        'body'   => $response->body(),
    ]);
    throw new \RuntimeException('支付创建失败');
}

$orderId = $response->json('order_id');
```

## API 一览

### PendingRequest（请求构建器）

| 方法 | 说明 |
|------|------|
| `baseUrl(string $url)` | 设置基础 URL |
| `withHeaders(array $headers)` | 批量设置请求头 |
| `withHeader(string $name, string $value)` | 设置单个请求头 |
| `withToken(string $token, string $type = 'Bearer')` | 设置 Bearer Token |
| `withBasicAuth(string $user, string $pass)` | 设置 Basic Auth |
| `withQuery(array $query)` | 设置全局查询参数 |
| `timeout(int $seconds)` | 设置总超时 |
| `connectTimeout(int $seconds)` | 设置连接超时 |
| `retry(int $times, int $delayMs = 100)` | 设置重试次数与间隔 |
| `withoutVerifying()` | 禁用 SSL 验证 |
| `asJson()` | JSON 格式（默认） |
| `asForm()` | 表单格式 |
| `asMultipart()` | Multipart 格式 |
| `get/post/put/patch/delete/head` | 发送请求 |

### ClientResponse（响应对象）

| 方法 | 说明 |
|------|------|
| `status()` | HTTP 状态码 |
| `body()` | 响应体原始字符串 |
| `json(?string $key, mixed $default)` | JSON 解码 |
| `header(string $name)` | 获取响应头（不区分大小写） |
| `headers()` | 全部响应头 |
| `ok()` / `successful()` | 是否 2xx |
| `failed()` | 是否 4xx/5xx |
| `clientError()` | 是否 4xx |
| `serverError()` | 是否 5xx |
| `throw()` | 失败时抛异常 |
