# 宝塔面板部署

本文介绍如何在宝塔 Linux 面板上部署 Lychee PHP 项目。

## 环境要求

- PHP >= 8.2
- Nginx
- MySQL / PostgreSQL / SQLite（按需）
- Composer

## 一、安装宝塔面板

参考宝塔官网教程安装 Linux 面板，安装完成后登录面板。

## 二、安装运行环境

在宝塔面板的 **软件商店** 中安装：

- **Nginx**（任意稳定版本）
- **PHP 8.2+**（推荐 8.3），安装时勾选以下扩展：
  - `fileinfo`
  - `openssl`
  - `pdo`
  - `mbstring`
  - `tokenizer`
  - `ctype`
  - `json`
  - `bcmath`
- **MySQL**（如使用数据库）
- **Redis**（如使用缓存 / 队列）

## 三、上传项目代码

1. 在宝塔 **文件** 中进入 `/www/wwwroot/`
2. 创建站点目录，例如 `/www/wwwroot/example.com`
3. 将项目代码上传到该目录（可使用 Git 克隆或直接上传压缩包解压）
4. 确保目录结构如下：

```
example.com/
├── app/
├── config/
├── public/
│   └── index.php
├── runtime/
├── vendor/
└── lee
```

5. 在项目根目录执行 Composer 安装：

```bash
cd /www/wwwroot/example.com
composer install --no-dev --optimize-autoloader
```

## 四、创建站点

1. 宝塔面板 → **网站** → **添加站点**
2. 填写域名，根目录选择项目的 `public` 目录，例如 `/www/wwwroot/example.com/public`
3. PHP 版本选择 8.2+
4. 点击提交

## 五、配置站点

### 5.1 设置运行目录

宝塔面板 → 网站 → 设置 → **网站目录**，将运行目录设置为 `/public`。

### 5.2 伪静态规则

宝塔面板 → 网站 → 设置 → **伪静态**，填入以下规则：

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

> **为什么用 try_files？**
>
> `try_files` 会先检查请求的静态文件是否存在，存在则直接返回，不存在则交给 `index.php` 处理。这是 Laravel / ThinkPHP 等框架的标准做法。

### 5.3 Nginx 配置（关键）

宝塔面板 → 网站 → 设置 → **配置文件**，重点检查以下配置：

#### ① 注释掉 error_page

宝塔默认会生成 `error_page 404 /404.html;`，这会导致框架返回的 404 被 Nginx 拦截。需要注释掉：

```nginx
#ERROR-PAGE-START
#error_page 404 /404.html;
#error_page 502 /502.html;
#ERROR-PAGE-END
```

#### ② 禁止 Nginx 拦截 PHP 错误响应

在 PHP 的 location 块中添加 `fastcgi_intercept_errors off;`：

```nginx
location ~ \.php$ {
    include enable-php-83.conf;
    fastcgi_intercept_errors off;
}
```

> **为什么要关 fastcgi_intercept_errors？**
>
> 宝塔的 PHP 配置文件（`enable-php-*.conf`）默认开启了 `fastcgi_intercept_errors on;`。这会导致 Nginx 拦截 PHP 返回的 4xx / 5xx 状态码，再根据 `error_page` 配置去找错误页面文件。框架已经自己处理了异常响应（返回 JSON 或 HTML），不需要 Nginx 再插手。

#### ③ 完整配置示例

```nginx
server
{
    listen 80;
    listen 443 ssl http2;
    server_name example.com;
    root /www/wwwroot/example.com/public;
    index index.php index.html index.htm;

    # SSL 配置（宝塔自动生成，保持不变）
    # ...

    # 错误页：注释掉，由框架自行处理
    #error_page 404 /404.html;

    # 伪静态
    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    # PHP 处理
    location ~ \.php$ {
        include enable-php-83.conf;
        fastcgi_intercept_errors off;
    }

    # 静态资源缓存
    location ~ .*\.(gif|jpg|jpeg|png|bmp|swf)$ {
        expires 30d;
    }

    location ~ .*\.(js|css)?$ {
        expires 12h;
    }

    access_log /www/wwwlogs/example.com.log;
    error_log /www/wwwlogs/example.com.error.log;
}
```

## 六、配置 .env

复制 `.env.example` 为 `.env`，修改数据库等配置：

```bash
cp .env.example .env
```

确保 `.env` 中的关键配置正确：

```env
APP_DEBUG=false
APP_URL=https://example.com
```

## 七、验证部署

1. 访问首页，确认能正常打开
2. 请求一个不存在的路由，应返回框架的 404 JSON：

```json
{"code":404,"msg":"Not Found"}
```

3. 查看 `runtime/log/` 目录是否有日志生成
4. 查看 Nginx 错误日志 `/www/wwwlogs/example.com.error.log` 是否有异常

## 八、SSL / HTTPS

宝塔面板 → 网站 → 设置 → **SSL**，可申请 Let's Encrypt 免费证书或上传已有证书。开启后建议勾选 **强制 HTTPS**。

## 常见问题

### 1. 请求异常接口时报 `open() ".../public/error" failed (2: No such file or directory)`

**原因**：宝塔的伪静态规则中有指向 `/error` 的 rewrite，或者 `error_page` + `fastcgi_intercept_errors` 拦截了框架的错误响应。

**解决**：
- 检查伪静态规则，使用本文 5.2 节的 `try_files` 规则
- 注释掉 `error_page 404 /404.html;`
- 添加 `fastcgi_intercept_errors off;`

### 2. 静态资源 404

**原因**：Nginx 的 `root` 没有指向 `public` 目录。

**解决**：确保站点根目录和运行目录都指向 `public`。

### 3. 上传大小限制

修改 PHP 配置（宝塔 → 软件商店 → PHP 设置 → 配置修改）：

```ini
upload_max_filesize = 50M
post_max_size = 50M
max_execution_time = 300
```