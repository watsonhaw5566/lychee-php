<?php

declare(strict_types=1);

namespace Lychee\http;

use Lychee\routing\RouteNotFoundException;
use Lychee\validate\ValidateException;
use Lychee\view\ExceptionRenderer;
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
 *   - render() 对 HttpException 按其 statusCode 返回对应状态码，其余统一 500
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
     * 记录异常类、消息、文件、行号、完整堆栈与上一个异常，
     * 并附带请求上下文（URL、方法、客户端 IP、登录 ID）便于排查。
     */
    public function report(Throwable $e): void
    {
        if (!$this->shouldReport($e) || !app()->has('log')) {
            return;
        }

        $context = [
            'exception' => $e::class,
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ];

        $previous = $e->getPrevious();
        if ($previous !== null) {
            $context['previous'] = $previous::class . ': ' . $previous->getMessage();
        }

        // 附带请求上下文（若请求已注册到容器）
        if (app()->has('request')) {
            try {
                $request = request();
                $context['url']    = $request->url();
                $context['method'] = $request->method;
                $context['ip']     = $request->ip;
                $loginId           = $request->loginId();
                if ($loginId !== null) {
                    $context['login_id'] = $loginId;
                }
            } catch (Throwable) {
                // 请求上下文获取失败不影响日志记录
            }
        }

        logger()->error($e->getMessage(), $context);
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
        $status = $e instanceof HttpException ? $e->getStatusCode() : 500;

        $debug        = (bool) config('app.debug', env('APP_DEBUG', false));
        $errorMessage = error_message();
        $showErrorMsg = (bool) config('app.show_error_msg', false);

        $message = $debug || $showErrorMsg
            ? ($e->getMessage() ?: $this->statusText($status))
            : $errorMessage;

        // 校验异常：保留字段级错误结构
        if ($e instanceof ValidateException) {
            return $this->renderValidate($e, $debug);
        }

        if ($this->shouldRenderHtml($request)) {
            return $this->renderHtml($status, $e, $request, $debug, $message);
        }

        if ($debug) {
            return new JsonResponse([
                'code'  => $status,
                'msg'   => $e->getMessage(),
                'data'  => null,
                'type'  => $e::class,
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ], $status, $this->exceptionHeaders($e));
        }

        return $this->jsonResponse($status, $message, $e);
    }

    /**
     * 构造 JSON 错误响应。
     */
    protected function jsonResponse(int $status, string $msg, ?Throwable $e = null): JsonResponse
    {
        return new JsonResponse([
            'code' => $status,
            'msg'  => $msg,
            'data' => null,
        ], $status, $this->exceptionHeaders($e));
    }

    /**
     * 渲染校验异常响应，保留字段级错误。
     */
    protected function renderValidate(ValidateException $e, bool $debug): JsonResponse
    {
        $errors = $e->getError();
        $msg    = is_array($errors) ? implode('；', $errors) : (string) $errors;

        $data = [
            'code' => $e->getStatusCode(),
            'msg'  => $msg,
            'data' => null,
        ];

        if (is_array($errors)) {
            $data['errors'] = $errors;
        } elseif ($e->getKey() !== null) {
            $data['errors'] = [$e->getKey() => (string) $errors];
        }

        if ($debug) {
            $data['type']  = $e::class;
            $data['file']  = $e->getFile();
            $data['line']  = $e->getLine();
            $data['trace'] = explode("\n", $e->getTraceAsString());
        }

        return new JsonResponse($data, $e->getStatusCode());
    }

    /**
     * 从异常中提取需要附加到响应的头信息。
     *
     * @return array<string, string>
     */
    protected function exceptionHeaders(?Throwable $e): array
    {
        if ($e instanceof HttpException) {
            return $e->getHeaders();
        }

        return [];
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
     *
     * 解析 Accept 头的 q 值，比较 text/html 与 application/json 的优先级。
     * 未指定 Accept 或包含通配符时默认返回 HTML。
     */
    protected function wantsHtml(Request $request): bool
    {
        $accept = $request->header('Accept', '');

        if ($accept === '') {
            return true;
        }

        $htmlQ  = $this->acceptQuality($accept, 'text/html');
        $jsonQ  = $this->acceptQuality($accept, 'application/json');
        $wildQ  = $this->acceptQuality($accept, '*/*');

        // 两者都未声明：看是否有通配符，无通配符则不渲染 HTML（按 JSON 处理更安全）
        if ($htmlQ === 0.0 && $jsonQ === 0.0) {
            return $wildQ > 0.0;
        }

        // HTML 优先级高于 JSON 时渲染 HTML
        return $htmlQ >= $jsonQ;
    }

    /**
     * 从 Accept 头中解析指定媒体类型的 q 值。
     *
     * 例如 `text/html;q=0.9,application/json;q=1.0` 中 text/html 的 q 值为 0.9。
     * 未匹配到该媒体类型时返回 0.0。
     */
    protected function acceptQuality(string $accept, string $mediaType): float
    {
        foreach (explode(',', $accept) as $part) {
            $segments = array_map('trim', explode(';', trim($part)));
            $type     = $segments[0] ?? '';

            if ($type !== $mediaType) {
                continue;
            }

            // 未指定 q 参数时默认为 1.0
            foreach (array_slice($segments, 1) as $param) {
                if (str_starts_with($param, 'q=')) {
                    return (float) substr($param, 2);
                }
            }

            return 1.0;
        }

        return 0.0;
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
