<?php

declare(strict_types=1);

use Lychee\http\Request;
use Lychee\http\Response;

if (!function_exists('env')) {
    /**
     * 读取环境变量，支持默认值。
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}

if (!function_exists('app')) {
    /**
     * 获取容器实例，或按标识解析服务。
     *
     * @param string|null $abstract 类名或容器标识，为 null 时返回容器本身
     * @param array       $vars     构造参数
     */
    function app(?string $abstract = null, array $vars = []): mixed
    {
        $container = \think\Container::getInstance();

        if ($abstract === null) {
            return $container;
        }

        return $container->make($abstract, $vars);
    }
}

if (!function_exists('config')) {
    /**
     * 获取配置项。
     *
     * @param  string|null $name    配置名（支持点号分隔），为 null 时返回全部配置
     * @param  mixed       $default 默认值
     */
    function config(?string $name = null, mixed $default = null): mixed
    {
        /** @var \Lychee\config\Config $config */
        $config = app('config');

        return $config->get($name, $default);
    }
}

if (!function_exists('base_path')) {
    /**
     * 获取应用基础目录。
     */
    function base_path(string $path = ''): string
    {
        $base = app('path.base');

        return $base . ($path ? ltrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '');
    }
}

if (!function_exists('app_path')) {
    /**
     * 获取应用目录（basePath/app）。
     */
    function app_path(string $path = ''): string
    {
        $app = app('path.app');

        return $app . ($path ? ltrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '');
    }
}

if (!function_exists('runtime_path')) {
    /**
     * 获取应用运行时目录。
     */
    function runtime_path(string $path = ''): string
    {
        $runtime = app('path.runtime');

        return $runtime . ($path ? ltrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '');
    }
}

if (!function_exists('public_path')) {
    /**
     * 获取 Web 根目录。
     */
    function public_path(string $path = ''): string
    {
        $public = app('path.public');

        return $public . ($path ? ltrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '');
    }
}

if (!function_exists('storage_path')) {
    /**
     * 获取存储目录。
     */
    function storage_path(string $path = ''): string
    {
        return runtime_path('storage' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : ''));
    }
}

if (!function_exists('logger')) {
    /**
     * 获取日志记录器，或记录一条 debug 级别日志。
     *
     * @param  string|null $message 日志消息，为 null 时返回 Logger 实例
     * @param  array       $context 上下文数据
     */
    function logger(?string $message = null, array $context = []): \Psr\Log\LoggerInterface
    {
        $log = app('log')->channel();

        if ($message !== null) {
            $log->debug($message, $context);
        }

        return $log;
    }
}

if (!function_exists('queue')) {
    /**
     * 获取队列连接器，或推送一个任务到队列。
     *
     * @param  string|null $job   任务类名或 "Class@method"，为 null 时返回连接器
     * @param  mixed       $data  任务数据
     * @param  string|null $queue 队列名
     */
    function queue(?string $job = null, mixed $data = '', ?string $queue = null): mixed
    {
        $manager = app('queue');

        if ($job === null) {
            return $manager->connection();
        }

        return $manager->connection()->push($job, $data, $queue);
    }
}

if (!function_exists('storage')) {
    /**
     * 获取文件系统磁盘。
     *
     * @param  string|null $disk 磁盘名，为 null 时使用默认磁盘
     */
    function storage(?string $disk = null): \Lychee\filesystem\Driver
    {
        return app('filesystem')->disk($disk);
    }
}

if (!function_exists('satoken')) {
    /**
     * 获取 SaToken 认证实例。
     */
    function satoken(): \Lychee\auth\SaToken
    {
        return app('satoken');
    }
}

if (!function_exists('request')) {
    /**
     * 获取当前请求对象。
     */
    function request(): Request
    {
        return app('request');
    }
}

if (!function_exists('cookie')) {
    /**
     * 获取或设置 Cookie。
     *
     * 当仅传入 $name 时读取 Cookie 值；同时传入 $value 时返回一个带 Set-Cookie 的 Response。
     *
     * @param  string      $name    Cookie 名称
     * @param  string|null $value   Cookie 值，为 null 时读取
     * @param  int         $minutes 过期分钟数
     * @param  array       $options 额外选项（path/domain/secure/httpOnly/sameSite）
     */
    function cookie(string $name, ?string $value = null, int $minutes = 0, array $options = []): mixed
    {
        if ($value === null) {
            return request()->cookie($name);
        }

        $response = new Response();

        return $response->cookie(
            name: $name,
            value: $value,
            minutes: $minutes,
            path: $options['path']         ?? '/',
            domain: $options['domain']     ?? null,
            secure: $options['secure']     ?? false,
            httpOnly: $options['httpOnly'] ?? true,
            sameSite: $options['sameSite'] ?? \Lychee\http\Cookie::SAME_SITE_LAX,
        );
    }
}

if (!function_exists('session')) {
    /**
     * 获取或设置 Session 值。
     *
     * @param  string|null $key   键名，为 null 时返回 Session 实例
     * @param  mixed       $value 值，为 null 时读取
     */
    function session(?string $key = null, mixed $value = null): mixed
    {
        /** @var \Lychee\session\Session $session */
        $session = app('session');

        if ($key === null) {
            return $session;
        }

        if (func_num_args() === 1) {
            return $session->get($key);
        }

        $session->set($key, $value);

        return null;
    }
}

if (!function_exists('response')) {
    /**
     * 构造响应对象。
     *
     * @param  string              $content 响应内容
     * @param  int                 $status  HTTP 状态码
     * @param  array<string,string> $headers 响应头
     */
    function response(string $content = '', int $status = 200, array $headers = []): Response
    {
        return new Response($content, $status, $headers);
    }
}

if (!function_exists('download')) {
    /**
     * 创建文件下载响应。
     *
     * 相对路径基于 public 目录解析，绝对路径直接使用。
     *
     * @param  string               $file    文件路径
     * @param  string|null          $name    下载时展示的文件名，为 null 时使用原文件名
     * @param  array<string,string> $headers 额外响应头
     */
    function download(string $file, ?string $name = null, array $headers = []): Response
    {
        return Response::download($file, $name, $headers);
    }
}

if (!function_exists('redirect')) {
    /**
     * 创建重定向响应。
     *
     * @param  string               $url     目标 URL
     * @param  int                  $status  HTTP 状态码（默认 302）
     * @param  array<string,string> $headers 额外响应头
     */
    function redirect(string $url, int $status = 302, array $headers = []): Response
    {
        return Response::redirect($url, $status, $headers);
    }
}

if (!function_exists('lang')) {
    /**
     * 翻译指定键。
     *
     * @param  string               $key     翻译键（分组.键名，支持点号嵌套）
     * @param  array<string, mixed> $replace 占位符替换值
     * @param  string|null          $locale  指定语言，为 null 时使用当前语言
     */
    function lang(string $key, array $replace = [], ?string $locale = null): string
    {
        /** @var \Lychee\i18n\I18n $i18n */
        $i18n = app('i18n');

        return $i18n->lang($key, $replace, $locale);
    }
}

if (!function_exists('view')) {
    /**
     * 渲染 Twig 模板。
     *
     * @param  string               $template 模板路径（相对 app/view，如 'user/index'、'user/index.twig' 或 'user/index.html'）
     * @param  array<string, mixed> $data     传递给模板的数据
     */
    function view(string $template, array $data = []): string
    {
        /** @var \Lychee\view\View $view */
        $view = app('view');

        return $view->render($template, $data);
    }
}

if (!function_exists('asset')) {
    /**
     * 生成 public 目录下静态资源的 URL。
     *
     * @param  string $path 资源相对路径，如 'css/app.css'
     */
    function asset(string $path): string
    {
        /** @var \Lychee\view\View $view */
        $view = app('view');

        return $view->asset($path);
    }
}
