<?php

declare(strict_types=1);

namespace Lychee\view;

/**
 * 模板引擎驱动接口。
 *
 * 框架默认实现为基于 Twig 的 {@see View}。如需使用 Liquid、Blade 等其他模板引擎，
 * 实现本接口并通过容器绑定覆盖默认驱动即可（无需修改框架核心代码）。
 *
 * @see View 默认的 Twig 实现
 */
interface ViewInterface
{
    /**
     * 渲染模板并返回 HTML 字符串。
     *
     * @param  string               $template 模板路径（相对模板根目录）
     * @param  array<string, mixed> $data     传递给模板的数据
     */
    public function render(string $template, array $data = []): string;

    /**
     * 判断模板是否存在。
     */
    public function exists(string $template): bool;

    /**
     * 生成 public 目录下静态资源的 URL。
     */
    public function asset(string $path): string;
}
