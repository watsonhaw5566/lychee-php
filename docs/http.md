# HTTP 请求与响应

## 请求 Request

```php
use Lychee\http\Request;

$request = request();

// 获取请求方法、URI
$method = $request->getMethod();
$uri    = $request->getUri();

// 获取参数
$id    = $request->input('id');
$name  = $request->input('name', '默认值');
$all   = $request->all();

// GET / POST
$get  = $request->get('page');
$post = $request->post('title');

// 请求头
$token = $request->header('Authorization');

// 判断请求类型
$request->isGet();
$request->isPost();
$request->isAjax();
$request->isJson();
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

## Cookie

```php
// 读取
$value = request()->cookie('name');
$value = cookie('name');

// 设置（返回带 Set-Cookie 的 Response）
$response = cookie('token', $value, minutes: 60);
$response = cookie('token', $value, minutes: 60, secure: true, httpOnly: true);
```
