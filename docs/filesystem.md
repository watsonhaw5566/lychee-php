# 文件系统 Filesystem

基于 Flysystem v3，支持 Local、阿里云 OSS、腾讯云 COS。

## 配置

创建 `config/filesystem.php`：

```php
return [
    'default' => 'local',
    'disks' => [
        'local' => [
            'type' => 'local',
            'root' => runtime_path('storage'),
        ],
        'aliyun' => [
            'type'          => 'aliyun',
            'access_id'     => 'your-access-key-id',
            'access_secret' => 'your-access-key-secret',
            'bucket'        => 'your-bucket',
            'endpoint'      => 'oss-cn-hangzhou.aliyuncs.com',
            'cdn'           => '',
        ],
        'qcloud' => [
            'type'       => 'qcloud',
            'app_id'     => 'your-app-id',
            'secret_id'  => 'your-secret-id',
            'secret_key' => 'your-secret-key',
            'region'     => 'ap-guangzhou',
            'bucket'     => 'your-bucket',
            'cdn'        => '',
        ],
    ],
];
```

## 使用

```php
$disk = storage();              // 默认磁盘
$disk = storage('aliyun');      // 指定磁盘

// 写入
$disk->put('hello.txt', 'Hello World');

// 追加写入
$disk->append('log.txt', 'new line');
$disk->prepend('log.txt', 'first line');

// 读取
$content = $disk->get('hello.txt');

// 判断是否存在
$disk->exists('hello.txt');          // 文件或目录
$disk->fileExists('hello.txt');      // 仅文件
$disk->directoryExists('uploads');   // 仅目录

// 删除
$disk->delete('hello.txt');
$disk->delete(['a.txt', 'b.txt']);

// 复制与移动
$disk->copy('a.txt', 'b.txt');
$disk->move('a.txt', 'dir/a.txt');

// 文件元信息
$disk->size('hello.txt');            // 字节
$disk->mimeType('hello.txt');        // MIME 类型
$disk->lastModified('hello.txt');    // 最后修改时间戳

// 可见性
$disk->getVisibility('hello.txt');   // public | private
$disk->setVisibility('hello.txt', 'public');

// 列出目录
$disk->files('uploads');             // 文件列表（不递归）
$disk->allFiles('uploads');          // 文件列表（递归）
$disk->directories('uploads');       // 目录列表
$disk->fileList('uploads')->toArray(); // 原始 DirectoryListing

// 目录操作
$disk->makeDirectory('uploads/sub');
$disk->deleteDirectory('uploads/sub');

// 获取访问 URL（local 磁盘需配置 url 项）
$url = $disk->url('hello.txt');

// 获取文件完整路径（仅 local 磁盘）
$fullPath = $disk->path('hello.txt');
```

## 辅助函数

```php
storage()->put('a.txt', 'content');
storage('qcloud')->put('b.txt', 'content');
```

## 上传文件

配合 `Request` 的文件读取能力，使用 `putFile()` 将上传文件存储到磁盘。

`putFile()` 会自动生成唯一文件名（保留原始扩展名），返回存储后的相对路径；`putFileAs()` 可指定文件名。

```php
// 在控制器中
$file = request()->file('avatar');

// 自动命名存储（返回 uploads/avatar/{hash}.jpg）
$path = storage()->putFile('uploads/avatar', $file);

// 指定文件名存储（返回 uploads/avatar/me.jpg）
$path = storage()->putFileAs('uploads/avatar', $file, 'me.jpg');

// 存储到指定磁盘（如阿里云 OSS）
$path = storage('aliyun')->putFile('uploads/avatar', $file);

// 获取访问 URL
$url = storage()->url($path);
```

### 完整示例：头像上传接口

```php
// routes/api.php
Route::post('/avatar', [UserController::class, 'avatar']);

// app/controller/UserController.php
namespace App\controller;

use Lychee\http\JsonResponse;
use Lychee\http\Request;

class UserController
{
    public function avatar(Request $request): JsonResponse
    {
        if (!$request->hasFile('avatar')) {
            return new JsonResponse(['code' => 1, 'msg' => '请选择上传文件']);
        }

        $file = request()->file('avatar');

        if (!$file->isValid()) {
            return new JsonResponse(['code' => 1, 'msg' => '文件上传失败']);
        }

        // 限制大小和类型
        if ($file->getSize() > 2 * 1024 * 1024) {
            return new JsonResponse(['code' => 1, 'msg' => '文件不能超过 2MB']);
        }

        if (!in_array($file->extension(), ['jpg', 'jpeg', 'png', 'gif'])) {
            return new JsonResponse(['code' => 1, 'msg' => '仅支持图片格式']);
        }

        // 存储到 public 磁盘并获取访问 URL
        $path = storage('public')->putFile('avatars', $file);
        $url  = storage('public')->url($path);

        return new JsonResponse(['code' => 0, 'data' => ['url' => $url, 'path' => $path]]);
    }
}
```

对应的 HTML 表单：

```html
<form action="/avatar" method="post" enctype="multipart/form-data">
    <input type="file" name="avatar" accept="image/*">
    <button type="submit">上传</button>
</form>
```

