<?php

declare(strict_types=1);

namespace Lychee\view;

use Throwable;

/**
 * 异常页面渲染器。
 *
 * 复用 View（Twig）模块渲染调试异常页，模板位于框架内置的 resources 目录，
 * 不依赖用户应用的 app/view 配置，因此在任何应用中均可直接使用。
 */
class ExceptionRenderer
{
    protected View $view;

    /**
     * @param string $cachePath Twig 编译缓存目录
     */
    public function __construct(string $cachePath)
    {
        $this->view = new View(
            viewPath: $this->resourcesPath(),
            cachePath: $cachePath,
            debug: true,
            baseUrl: '',
            extensions: ['.twig'],
        );
    }

    /**
     * 渲染异常页面并返回 HTML 字符串。
     *
     * @param int         $status  HTTP 状态码
     * @param Throwable   $e       异常实例
     * @param string      $method  请求方法
     * @param string      $url     请求 URL
     */
    public function render(int $status, Throwable $e, string $method = 'GET', string $url = '/'): string
    {
        return $this->view->render('exception', [
            'status'    => $status,
            'message'   => $e->getMessage() ?: 'Internal Server Error',
            'exception' => $e::class,
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'method'    => $method,
            'url'       => $url,
            'trace'     => $this->formatTrace($e),
            'logo'      => $this->logoDataUri(),
        ]);
    }

    /**
     * 渲染非调试模式下的通用错误页面。
     *
     * 用于生产环境，仅展示状态码与通用描述，不暴露异常详情。
     *
     * @param int    $status  HTTP 状态码
     * @param string $message 状态描述
     */
    public function renderError(int $status, string $message): string
    {
        return $this->view->render('error', [
            'status'  => $status,
            'message' => $message,
            'logo'    => $this->logoDataUri(),
        ]);
    }

    /**
     * 读取框架内置 Logo 并转为 base64 Data URI，使异常页自包含、不依赖外部静态资源。
     */
    protected function logoDataUri(): string
    {
        $logo = $this->resourcesPath() . DIRECTORY_SEPARATOR . 'logo.png';

        if (!is_file($logo)) {
            return '';
        }

        $data = (string) file_get_contents($logo);

        return 'data:image/png;base64,' . base64_encode($data);
    }

    /**
     * 获取框架内置模板资源目录。
     */
    protected function resourcesPath(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'resources';
    }

    /**
     * 将异常堆栈格式化为结构化列表，每帧包含 call（调用）与 location（文件:行号）。
     *
     * @return array<int, array{call: string, location: string}>
     */
    protected function formatTrace(Throwable $e): array
    {
        $frames = [];

        // 第一帧：异常抛出位置
        $frames[] = [
            'call'     => $e::class . '  ⟵  thrown',
            'location' => $e->getFile() . ':' . $e->getLine(),
        ];

        foreach ($e->getTrace() as $frame) {
            $call = '';
            if (isset($frame['class'])) {
                $call = $frame['class'] . ($frame['type'] ?? '::') . ($frame['function'] ?? '');
            } elseif (isset($frame['function'])) {
                $call = $frame['function'];
            }

            $file = $frame['file'] ?? '[internal function]';
            $line = $frame['line'] ?? 0;

            $frames[] = [
                'call'     => $call !== '' ? $call : '[internal]',
                'location' => $line > 0 ? $file . ':' . $line : $file,
            ];
        }

        return $frames;
    }
}
