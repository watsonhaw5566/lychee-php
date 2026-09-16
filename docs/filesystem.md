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
        'cos' => [
            'type'       => 'cos',
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
$disk->write('hello.txt', 'Hello World');
$disk->writeStream('file.zip', fopen('local.zip', 'r'));

// 读取
$content = $disk->read('hello.txt');
$stream  = $disk->readStream('file.zip');

// 判断是否存在
$disk->fileExists('hello.txt');

// 删除
$disk->delete('hello.txt');

// 列出文件
$files = $disk->listContents('/')->toArray();

// 获取 URL
$url = $disk->getUrl('hello.txt');
```

## 辅助函数

```php
storage()->write('a.txt', 'content');
storage('cos')->write('b.txt', 'content');
```
