# HTTP 请求与响应

## 请求 Request

```php
use Lychee\http\Request;

$request = request();

// 获取请求方法、URI
$method = $request->getMethod();
$uri    = $request->getUri();

// 获取参数（合并 GET / POST / 路由参数）
$id    = $request->param('id');
$name  = $request->param('name', '默认值');
$all   = $request->param();

// GET / POST
$get  = $request->get('page');
$post = $request->post('title');

// 请求头
$token = $request->header('Authorization');

// 客户端 IP（自动识别 X-Real-IP / X-Forwarded-For，回退 REMOTE_ADDR）
$ip = $request->ip;

// 判断请求类型
$request->isGet();
$request->isPost();
$request->isAjax();
$request->isJson();

// 判断是否为移动设备（基于 User-Agent）
$request->isMobile();

// 域名与协议（反向代理场景自动识别 X-Forwarded-Host / X-Forwarded-Proto）
$scheme = $request->scheme();   // http | https
$host   = $request->host();     // example.com:8080
$domain = $request->domain();   // https://example.com:8080
```

## 响应 Response

```php
use Lychee\http\Response;

return new Response('Hello', 200, ['X-Custom' => 'value']);

// JSON 响应
use Lychee\http\JsonResponse;
return new JsonResponse(['code' => 0, 'data' => $data]);

// 辅助函数
return response('content', 200);
```

## 文件下载 Download

下载 public 目录下的文件（相对路径自动基于 public 目录解析，也支持绝对路径）：

```php
use Lychee\http\Response;

// 下载 public/report.pdf
return Response::download('report.pdf');

// 自定义下载文件名
return Response::download('report.pdf', '年度报告.pdf');

// 辅助函数
return download('report.pdf', '年度报告.pdf');
```

文件不存在时抛出 `HttpException`（404）。响应自动设置 `Content-Type`、`Content-Length`、`Content-Disposition: attachment` 等头。

## 重定向 Redirect

```php
use Lychee\http\Response;

// 302 临时重定向
return Response::redirect('/login');

// 301 永久重定向
return Response::redirect('/new-url', 301);

// 辅助函数
return redirect('/login');
```

## Cookie

```php
// 读取
$value = request()->cookie('name');
$value = cookie('name');

// 设置（返回带 Set-Cookie 的 Response）
$response = cookie('token', $value, minutes: 60);
$response = cookie('token', $value, minutes: 60, secure: true, httpOnly: true);
```
