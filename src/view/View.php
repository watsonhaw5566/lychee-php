<?php

declare(strict_types=1);

namespace Lychee\view;

use RuntimeException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * 基于 Twig 的模板引擎。
 *
 * 模板文件默认从 app/view 目录读取，编译缓存写入 runtime/twig。
 */
class View
{
    protected Environment $twig;

    public function __construct(
        protected readonly string $viewPath,
        protected readonly string $cachePath,
        protected readonly bool $debug = false,
        protected readonly string $baseUrl = '',
    ) {
        if (!is_dir($this->viewPath)) {
            throw new RuntimeException("View directory not found: {$this->viewPath}");
        }

        $loader = new FilesystemLoader($this->viewPath);

        $this->twig = new Environment($loader, [
            'cache'            => $this->cachePath,
            'debug'            => $this->debug,
            'auto_reload'      => true,
            'strict_variables' => false,
            'autoescape'       => 'html',
        ]);

        $this->registerDefaults();
    }

    /**
     * 注册框架内置的 Twig 函数与过滤器。
     */
    protected function registerDefaults(): void
    {
        // asset() —— 生成 public 目录下静态资源的 URL
        $this->twig->addFunction(new TwigFunction('asset', function (string $path): string {
            return $this->asset($path);
        }));

        // url() —— 生成站点 URL
        $this->twig->addFunction(new TwigFunction('url', function (string $path = ''): string {
            return $this->baseUrl . '/' . ltrim($path, '/');
        }));
    }

    /**
     * 生成 public 目录下静态资源的 URL。
     */
    public function asset(string $path): string
    {
        $path = ltrim($path, '/');

        return $this->baseUrl . '/' . $path;
    }

    /**
     * 渲染模板并返回 HTML 字符串。
     *
     * @param  string               $template 模板路径（相对 view 目录，如 'user/index.html'）
     * @param  array<string, mixed> $data     传递给模板的数据
     */
    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    /**
     * 判断模板是否存在。
     */
    public function exists(string $template): bool
    {
        return $this->twig->getLoader()->exists($template);
    }

    /**
     * 获取 Twig 环境实例，以便注册自定义函数、过滤器等扩展。
     */
    public function getTwig(): Environment
    {
        return $this->twig;
    }
}
