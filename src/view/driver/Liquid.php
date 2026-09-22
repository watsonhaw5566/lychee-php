<?php

declare(strict_types=1);

namespace Lychee\view\driver;

use Liquid\Cache\File as FileCache;
use Liquid\Liquid as LiquidEngine;
use Liquid\Template as LiquidTemplate;
use Lychee\view\ViewInterface;
use RuntimeException;

/**
 * 基于 liquid/liquid 的模板引擎驱动。
 *
 * 使用前需安装依赖：composer require liquid/liquid
 *
 * @see https://github.com/kalimatas/php-liquid
 */
class Liquid implements ViewInterface
{
    protected string $viewPath;
    protected string $cachePath;
    protected string $baseUrl;

    /** @var string[] 允许的模板后缀 */
    protected array $extensions;

    /**
     * @param  string   $viewPath   模板根目录
     * @param  string   $cachePath  编译缓存目录
     * @param  bool     $debug      是否开启调试（Liquid 无独立调试开关，保留以兼容接口）
     * @param  string   $baseUrl    静态资源基础 URL
     * @param  string[] $extensions 允许的模板后缀
     */
    public function __construct(
        string $viewPath,
        string $cachePath,
        bool $debug = false,
        string $baseUrl = '',
        array $extensions = ['.liquid'],
    ) {
        if (!class_exists(LiquidTemplate::class)) {
            throw new RuntimeException(
                'Liquid template engine requires the "liquid/liquid" package. ' .
                'Install it via: composer require liquid/liquid'
            );
        }

        $this->viewPath   = rtrim($viewPath, '/\\');
        $this->cachePath  = rtrim($cachePath, '/\\');
        $this->baseUrl    = rtrim($baseUrl, '/\\');
        $this->extensions = $extensions;

        // 默认开启 HTML 自动转义，与 Twig 的 autoescape 行为一致
        LiquidEngine::set('ESCAPE_BY_DEFAULT', true);
    }

    /**
     * 将模板名称解析为实际的模板文件路径。
     */
    protected function resolveTemplate(string $template): string
    {
        if (str_contains($template, DIRECTORY_SEPARATOR) && is_file($template)) {
            return $template;
        }

        $candidate = $this->viewPath . DIRECTORY_SEPARATOR . $template;

        if (is_file($candidate)) {
            return $candidate;
        }

        foreach ($this->extensions as $ext) {
            if (str_ends_with($template, $ext)) {
                return $candidate;
            }
        }

        foreach ($this->extensions as $ext) {
            if (is_file($candidate . $ext)) {
                return $candidate . $ext;
            }
        }

        return $candidate;
    }

    public function render(string $template, array $data = []): string
    {
        $file = $this->resolveTemplate($template);

        $tpl = new LiquidTemplate();

        if ($this->cachePath !== '' && is_dir($this->cachePath)) {
            $tpl->setCache(new FileCache($this->cachePath));
        }

        $tpl->parseFile($file);

        return $tpl->render($data);
    }

    public function exists(string $template): bool
    {
        return is_file($this->resolveTemplate($template));
    }

    public function asset(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }
}
