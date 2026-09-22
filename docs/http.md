# HTTP 请求与响应

## 请求 Request

```php
use Lychee\http\Request;

$request = request();

// 获取请求方法、URI
$method = $request->getMethod();
$uri    = $request->getUri();

// 获取参数（合并 GET / POST / 上传文件 / 路由参数）
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

## 文件上传 Upload

`Request` 仅负责读取上传文件的信息，文件的存储由 `filesystem` 模块处理（见 [文件系统](filesystem.md#上传文件)）。

`UploadedFile` 继承自 `think\File`（基于 `SplFileInfo`），因此可直接作为 `think-validate` 的 `file` / `image` / `fileExt` / `fileMime` / `fileSize` 规则的校验对象（见 [数据验证](validation.md)）。

```php
use Lychee\http\UploadedFile;

$request = request();

// 判断是否有上传文件
if ($request->hasFile('avatar')) {
    $file = $request->file('avatar');

    // 文件元信息
    $file->getOriginalName();  // 原始文件名，如 photo.jpg
    $file->extension();        // 扩展名（取自原始文件名），如 jpg
    $file->getSize();          // 文件大小（字节）
    $file->getOriginalMime();  // 客户端声明的 MIME（不可信），getMimeType() 为其别名
    $file->getMime();          // 基于文件内容检测的真实 MIME
    $file->getTempName();      // 服务器临时路径
    $file->isValid();          // 上传是否成功
    $file->getContent();       // 文件内容字符串
    $file->getStream();        // 文件流资源

    // 交给 filesystem 模块存储
    $path = storage()->putFile('uploads/avatar', $file);
}

// 多文件上传（同一字段名，如 <input type="file" name="photos[]" multiple>）
$photos = $request->file('photos'); // 返回 UploadedFile[] 数组
foreach ($photos as $photo) {
    storage()->putFile('uploads/photos', $photo);
}

// 获取全部上传文件
$all = $request->file();
```

前端表单需设置 `enctype="multipart/form-data"`。

### 上传文件校验

`param()` 会合并上传文件，可直接把 `$request->param()` 交给校验器：

```php
use think\Validate;

$validate = new Validate();
$validate->rule([
    'avatar' => 'require|image|fileExt:jpg,png|fileMime:image/jpeg,image/png|fileSize:2097152',
]);

if (!$validate->check($request->param())) {
    // 校验失败：未上传 / 不是图片 / 后缀不允许 / 类型不符 / 超过 2MB
}
```

| 规则 | 说明 |
| --- | --- |
| `file` | 必须是有效的上传文件（`UploadedFile` 实例） |
| `image` | 必须是图片（GIF/JPG/PNG/BMP 等，按文件内容识别） |
| `fileExt:jpg,png` | 原始文件名后缀必须在允许列表中 |
| `fileMime:image/jpeg,image/png` | 文件内容检测出的 MIME 必须在允许列表中 |
| `fileSize:2097152` | 文件大小不得超过指定字节数 |

> 注意：`fileMime` 基于文件内容检测，比客户端上传的 `getOriginalMime()`（可伪造）更可靠。

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
