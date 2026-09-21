<?php

declare(strict_types=1);

namespace Lychee\http;

use Lychee\routing\RouteNotFoundException;
use Lychee\view\ExceptionRenderer;
use think\exception\ValidateException;
use Throwable;

/**
 * 应用异常处理器。
 *
 * 负责将未捕获的异常转换为 HTTP 响应，并按需记录日志。
 * 用户可继承此类并覆盖 report() / render() 来自定义异常处理逻辑，
 * 然后在 config/app.php 中通过 exception_handler 配置指定子类。
 *
 * 约定：
 *   - $ignoreReport 中的异常类不写入日志（如 404、校验失败等预期内错误）
 *   - render() 对已知异常返回对应状态码，其余统一 500
 *   - 浏览器请求渲染 HTML 异常页，JSON 请求返回结构化错误
 */
class ExceptionHandler
{
    /**
     * 不需要记录日志的异常类列表。
     *
     * @var array<class-string<Throwable>>
     */
    protected array $ignoreReport = [
        HttpException::class,
        RouteNotFoundException::class,
        ValidateException::class,
    ];

    /**
     * 记录异常信息（日志）。
     *
     * 仅记录异常类、文件与行号，不写入完整堆栈（避免日志过长）。
     * 需要堆栈时可在子类覆盖 report() 自行追加。
     */
    public function report(Throwable $e): void
    {
        if ($this->shouldReport($e) && app()->has('log')) {
            logger()->error($e->getMessage(), [
                'exception' => $e::class,
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
        }
    }

    /**
     * 判断是否需要记录该异常。
     */
    protected function shouldReport(Throwable $e): bool
    {
        foreach ($this->ignoreReport as $class) {
            if ($e instanceof $class) {
                return false;
            }
        }

        return true;
    }

    /**
     * 将异常渲染为 HTTP 响应。
     *
     * 子类可覆盖此方法完全自定义响应格式。
     */
    public function render(Request $request, Throwable $e): Response
    {
        // 校验异常统一返回 400
        if ($e instanceof ValidateException) {
            $errors = $e->getError();
            $msg    = is_array($errors) ? implode('；', $errors) : (string) $errors;

            return $this->jsonResponse(400, $msg);
        }

        $status = 500;

        if ($e instanceof RouteNotFoundException) {
            $status = 404;
        } elseif ($e instanceof HttpException) {
            $status = $e->getStatusCode();
        }

        $debug        = (bool) config('app.debug', env('APP_DEBUG', false));
        $errorMessage = error_message();
        $showErrorMsg = (bool) config('app.show_error_msg', false);

        $message = $debug || $showErrorMsg
            ? ($e->getMessage() ?: $this->statusText($status))
            : $errorMessage;

        if ($this->shouldRenderHtml($request)) {
            return $this->renderHtml($status, $e, $request, $debug, $message);
        }

        if ($debug) {
            return new JsonResponse([
                'code'  => $status,
                'msg'   => $e->getMessage(),
                'type'  => $e::class,
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ], $status);
        }

        return $this->jsonResponse($status, $message);
    }

    /**
     * 构造 JSON 错误响应。
     */
    protected function jsonResponse(int $status, string $msg): JsonResponse
    {
        return new JsonResponse([
            'code' => $status,
            'msg'  => $msg,
        ], $status);
    }

    /**
     * 渲染 HTML 异常页。
     */
    protected function renderHtml(
        int $status,
        Throwable $e,
        Request $request,
        bool $debug,
        string $message,
    ): Response {
        $renderer = new ExceptionRenderer(
            cachePath: (string) app('path.runtime') . 'twig',
        );

        if ($debug) {
            $html = $renderer->render(
                status: $status,
                e: $e,
                method: $request->method,
                url: $request->path,
            );
        } else {
            $html = $renderer->renderError($status, $message);
        }

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * 判断是否渲染 HTML 异常页。
     *
     * 由 config/app.php 的 exception_render 控制：
     *   - 'auto'（默认）：按 Accept header 判断，浏览器请求渲染 HTML，JSON 请求返回 JSON
     *   - 'html'：始终渲染 HTML（传统 Web 应用）
     *   - 'json'：始终返回 JSON（纯 API 应用）
     */
    protected function shouldRenderHtml(Request $request): bool
    {
        $render = (string) config('app.exception_render', 'auto');

        return match ($render) {
            'html'  => true,
            'json'  => false,
            default => $this->wantsHtml($request),
        };
    }

    /**
     * 判断请求是否期望 HTML 响应（浏览器访问）。
     */
    protected function wantsHtml(Request $request): bool
    {
        $accept = $request->header('Accept', '');

        if (str_contains($accept, 'application/json')) {
            return false;
        }

        return str_contains($accept, 'text/html') || $accept === '' || str_contains($accept, '*/*');
    }

    /**
     * 根据状态码返回通用错误描述。
     */
    protected function statusText(int $status): string
    {
        return match ($status) {
            404     => 'Not Found',
            403     => 'Forbidden',
            401     => 'Unauthorized',
            405     => 'Method Not Allowed',
            422     => 'Unprocessable Entity',
            default => 'Server Error',
        };
    }
}
