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

## 九、前后端分离部署

前后端分离项目（Vue / React 等 SPA + Lychee PHP API）与普通部署的差异仅在于：**API 侧的配置**和**前端静态文件的部署**。后端代码的上传、Composer 安装、站点创建等步骤与上文一致。

### 9.1 部署方式

| 方式 | 说明 | 是否需要 CORS |
| --- | --- | --- |
| **SPA 放在 public/admin**（推荐） | 前端构建产物放入后端 `public/admin`，单站点单项目 | 否（同源） |
| **同域分目录 php-fpm 直连** | 前后端分目录，`/api` 路径直接交给 php-fpm | 否（同源） |
| **Nuxt SSR 同域共存** | Nuxt 由 PM2 守护，Nginx 反代；与 admin、API 同域 | 否（同源） |
| **跨域独立部署** | 前端与 API 使用不同域名 / 子域名 | 是 |

### 9.2 API 配置差异

#### ① 异常渲染为 JSON

API 项目建议在 `config/app.php` 中将异常响应固定为 JSON，避免浏览器收到 HTML 错误页：

```php
// config/app.php
return [
    'exception_render' => 'json',
];
```

#### ② 认证改为 Header 模式

前后端分离推荐 Token 鉴权，前端在请求头中携带 `Authorization: Bearer <token>`。在 `config/satoken.php` 中将 token 读取驱动设为 `header`：

```php
// config/satoken.php
return [
    'token_name'   => '',            // 留空则从 Authorization: Bearer 读取
    'token_reader' => 'header',      // 仅从 HTTP Header 读取
    'timeout'      => 86400 * 7,
    'auto_renew'   => true,
];
```

> 详细认证配置见 [认证 Auth](./auth)。

### 9.3 方式一：SPA 放在 public/admin（推荐）

将前端构建产物放入后端项目的 `public/admin` 目录，Nginx 同时提供 API 与后台页面，是最简单的部署方式。

**目录结构：**

```
example.com/
├── app/
├── config/
├── public/
│   ├── index.php        # 后端 API 入口
│   └── admin/           # SPA 构建产物（dist 内容）
│       ├── index.html
│       └── assets/
├── vendor/
└── lee
```

**① 前端构建时指定子路径**

Vite 配置 `base`，路由配置 basename，三者路径必须一致：

```js
// vite.config.js
export default defineConfig({
  base: '/admin/',
})
```

```js
// Vue Router
createWebHistory('/admin/')

// React Router
<BrowserRouter basename="/admin">
```

**② 上传产物**：将 `npm run build` 产出的 `dist/` 内容上传到 `public/admin/`，然后正常构建发布后端代码即可。

**③ Nginx 配置**（站点根目录仍指向 `public`）：

```nginx
server
{
    listen 80;
    listen 443 ssl http2;
    server_name example.com;
    root /www/wwwroot/example.com/public;
    index index.php index.html;

    # 错误页：注释掉，由框架自行处理
    #error_page 404 /404.html;

    # 管理后台 SPA：文件存在直接返回，否则回退到 /admin/index.html
    location ^~ /admin/ {
        try_files $uri $uri/ /admin/index.html;
    }

    # 访问 /admin 时自动补斜杠
    location = /admin {
        return 301 /admin/;
    }

    # 其余路径交给框架处理（API）
    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include enable-php-83.conf;
        fastcgi_intercept_errors off;
    }

    access_log /www/wwwlogs/example.com.log;
    error_log /www/wwwlogs/example.com.error.log;
}
```

**关键说明：**

- `^~ /admin/` 前缀匹配优先级高于 `.php` 正则，确保后台的静态文件与前端路由不会被交给 PHP 处理
- `try_files ... /admin/index.html` 让 `/admin/users` 等 History 路由刷新时回退到 SPA 入口
- API 与页面同源，前端请求直接用相对路径（如 `/login`、`/users`），无需 CORS、无需 `/api` 前缀
- 注意后端不要定义 `/admin` 开头的 API 路由，避免与后台路径冲突；若 API 统一使用 `/api` 前缀则无此问题

### 9.4 方式二：同域分目录 php-fpm 直连

当前后端代码需要分开维护时，可在**同一个 Nginx 站点**内处理：静态文件由 Nginx 直接返回，`/api/` 请求直接交给 php-fpm 执行后端的 `index.php`。

```nginx
server
{
    listen 80;
    listen 443 ssl http2;
    server_name app.example.com;
    root /www/wwwroot/app.example.com;          # 前端静态文件目录
    index index.html;

    # 前端 SPA：路由回退到 index.html
    location / {
        try_files $uri $uri/ /index.html;
    }

    # 后端 API：php-fpm 直连
    location ^~ /api/ {
        root /www/wwwroot/api.example.com/public;
        rewrite ^/api/(.*)$ /$1 break;

        include enable-php-83.conf;
        fastcgi_param SCRIPT_FILENAME /www/wwwroot/api.example.com/public/index.php;
        fastcgi_param REQUEST_URI $uri$is_args$args;
        fastcgi_intercept_errors off;
    }

    # 静态资源缓存
    location ~ .*\.(js|css|png|jpg|jpeg|gif|svg|ico|woff2?)$ {
        expires 30d;
    }
}
```

**关键说明：**

- `root` 指向后端 `public`，`rewrite` 去掉 `/api` 前缀，后端路由无需加 `/api`
- `include enable-php-83.conf;` 引入宝塔 php-fpm 配置，版本号按实际 PHP 修改（82 / 83 / 84）
- `fastcgi_param SCRIPT_FILENAME .../index.php;` 所有 API 请求统一走后端入口
- `fastcgi_param REQUEST_URI $uri$is_args$args;` 将去前缀后的路径传给框架
- `fastcgi_intercept_errors off;` 禁止 Nginx 拦截 PHP 错误响应

### 9.5 方式三：跨域独立部署 + CORS 配置

前端独立创建**纯静态站点**：将 `npm run build` 产出的 `dist/` 内容上传到站点目录（如 `/www/wwwroot/app.example.com/`），宝塔添加站点时 PHP 版本选择**纯静态**，伪静态填入 SPA 回退规则：

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

由于前端与 API 不在同一域名（如 `app.example.com` 调用 `api.example.com`），还需在后端配置 CORS。

```php
// config/cors.php
return [
    'allowed_origins'     => ['https://app.example.com'],
    'allowed_methods'     => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_headers'     => ['Content-Type', 'Authorization', 'X-Requested-With'],
    'allowed_credentials' => false,
    'max_age'             => 86400,
];
```

注册为全局中间件：

```php
// config/middleware.php
return [
    \Lychee\cors\CorsMiddleware::class,
];
```

> 详细 CORS 配置见 [跨域 CORS](./cors)。

### 9.6 前端请求示例（axios）

**方式一 / 方式二（同源）：**

```js
// 方式一（public/admin）：API 与页面同源，直接用相对路径
// 方式二（分目录直连）：baseURL 用 /api
const http = axios.create({
  baseURL: '/api',   // 方式一可省略或按实际接口前缀填写
  timeout: 10000,
})
```

**跨域独立部署：**

```js
import axios from 'axios'

const http = axios.create({
  baseURL: 'https://api.example.com',
  timeout: 10000,
})

// 请求拦截器：携带 Token
http.interceptors.request.use(config => {
  const token = localStorage.getItem('token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// 响应拦截器：处理 401
http.interceptors.response.use(
  res => res,
  err => {
    if (err.response?.status === 401) {
      localStorage.removeItem('token')
      location.href = '/login'
    }
    return Promise.reject(err)
  },
)
```

### 9.7 方式四：Nuxt SSR 同域部署

Nuxt 默认是 SSR（服务端渲染），需要一个**常驻 Node.js 进程**，由 PM2 守护，Nginx 反向代理过去，不能像静态 SPA 一样直接由 Nginx 提供文件。典型架构是 Nuxt 前台、admin 后台、PHP API 三端同域共存：

```
https://example.com/
├── /          → Nuxt SSR（Node:3000，PM2 守护，Nginx 反代）
├── /admin/    → 管理后台 SPA（public/admin 静态文件）
└── /api/      → Lychee PHP（php-fpm 直连）
```

三者同源，浏览器侧无 CORS 问题。

#### ① 宝塔安装 Node 环境

软件商店安装 **PM2管理器**，Node 选择 18 LTS 或 20 LTS。

#### ② 构建并以 PM2 启动

在 Nuxt 项目目录（如 `/www/wwwroot/nuxt-app`）执行 `npm run build`，然后创建 `ecosystem.config.js`：

```js
module.exports = {
  apps: [{
    name: 'nuxt-app',
    script: './.output/server/index.mjs',
    exec_mode: 'cluster',
    instances: 'max',
    env: {
      PORT: 3000,
      NODE_ENV: 'production',
      // SSR 服务端内部回环访问 API
      NUXT_API_BASE: 'http://127.0.0.1/api',
    },
  }],
}
```

```bash
pm2 start ecosystem.config.js
pm2 save
pm2 startup    # 开机自启
```

#### ③ Nginx 配置

站点根目录仍指向 PHP 项目的 `public`。WebSocket 的 `map` 指令需放在 `http` 块中（宝塔可写入 `/www/server/nginx/conf/config/enable.conf` 或在站点配置外层，勿放进 server）：

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}
```

站点 server 配置：

```nginx
server
{
    listen 80;
    listen 443 ssl http2;
    server_name example.com;
    root /www/wwwroot/example.com/public;
    index index.php;

    #error_page 404 /404.html;

    # 管理后台：静态 SPA
    location ^~ /admin/ {
        try_files $uri $uri/ /admin/index.html;
    }
    location = /admin {
        return 301 /admin/;
    }

    # PHP API：php-fpm 直连
    location ^~ /api/ {
        rewrite ^/api/(.*)$ /$1 break;
        include enable-php-83.conf;
        fastcgi_param SCRIPT_FILENAME /www/wwwroot/example.com/public/index.php;
        fastcgi_param REQUEST_URI $uri$is_args$args;
        fastcgi_intercept_errors off;
    }

    # Nuxt 静态构建资源：Nginx 直接返回，减轻 Node 压力
    location /_nuxt/ {
        alias /www/wwwroot/nuxt-app/.output/public/_nuxt/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # 其余路径：反向代理给 Nuxt SSR
    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
    }
}
```

匹配优先级：`^~ /admin/`、`^~ /api/` 前缀命中后不再检查正则，`/_nuxt/` 次之，剩余路径全部落到 `location /` 交给 Nuxt。

#### ④ Nuxt 侧 API 地址（SSR 的关键点）

SSR 下数据请求发生在两处，地址不同：服务端渲染时走内部回环 `http://127.0.0.1/api`，浏览器 hydration 后走同源相对路径 `/api`。

```ts
// nuxt.config.ts
export default defineNuxtConfig({
  runtimeConfig: {
    apiBase: process.env.NUXT_API_BASE || 'http://127.0.0.1/api', // 服务端
    public: {
      apiBase: '/api',                                              // 浏览器端
    },
  },
})
```

```ts
// composables/useApi.ts
export function useApi() {
  const config = useRuntimeConfig()
  const base = import.meta.server ? config.apiBase : config.public.apiBase
  return (url: string) => $fetch(base + url, { credentials: 'include' })
}
```

首屏 SSR 需要鉴权时，可用 `useRequestHeaders(['cookie', 'authorization'])` 将用户请求头透传给服务端发起的 API 请求。

#### ⑤ 备选：Nuxt 纯静态生成

若前台不需要 SSR，可执行 `nuxi generate` 产出纯静态文件（`.output/public/`），此时退化为静态站：配置 `app: { baseURL: '/m/' }` 后上传到 `public/m/`，Nginx 仿照 admin 添加 `try_files $uri $uri/ /m/index.html;` 即可，无需 Node / PM2，但失去 SSR 的 SEO 与首屏优势。

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

同时在 Nginx 的 `server` 块中添加：

```nginx
client_max_body_size 50m;
```

### 4. 前端 SPA 刷新页面 404

**原因**：Vue / React 的 History 模式下，Nginx 没有将路由回退到 `index.html`。

**解决**：在前端站点的伪静态中添加 `try_files $uri $uri/ /index.html;`。

### 5. 跨域请求被浏览器拦截

**原因**：后端未配置 CORS 或 `allowed_origins` 不包含前端域名。

**解决**：
- 创建 `config/cors.php` 并注册 `CorsMiddleware` 为全局中间件（见 9.5 节）
- 确保 `allowed_origins` 包含前端实际域名（含协议）
- 检查 `allowed_headers` 是否包含 `Authorization`

### 6. 401 未生效，始终能访问

**原因**：`SatokenMiddleware` 未挂载到对应控制器或方法。

**解决**：在需要鉴权的控制器或方法上添加 `#[Middleware(SatokenMiddleware::class)]`，登录接口用 `#[WithoutMiddleware]` 排除。

### 7. php-fpm 直连方案下获取不到真实客户端 IP

php-fpm 直连方案没有代理层，客户端 IP 直接由 `$remote_addr` 传递给 PHP，框架的 IP 方法即可正确获取。若服务器前面还有 CDN / 负载均衡，需在 `location ^~ /api/` 中补充：

```nginx
fastcgi_param REMOTE_ADDR $http_x_forwarded_for;
```

> 注意：直接信任 `X-Forwarded-For` 有伪造风险，生产环境应仅在确认前置代理可信时使用。