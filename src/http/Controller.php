<?php

declare(strict_types=1);

namespace Lychee\http;

use Lychee\container\Container;

/**
 * 基础控制器：提供请求注入、initialize 生命周期与统一 JSON 响应。
 *
 * 无需 CRUD 的普通控制器继承此类即可；需要资源路由与零代码 CRUD 的控制器
 * 请继承 {@see \Lychee\routing\ResourceController}。
 */
abstract class Controller
{
    protected Container $app;
    protected Request $request;

    final public function __construct(Container $container)
    {
        $this->app     = $container;
        $this->request = $container->get(Request::class);
    }

    /**
     * 控制器初始化钩子。
     *
     * 由 Kernel 在中间件执行完毕后、方法调用前调用。
     * 子类覆盖此方法做初始化，无需调用 parent。
     */
    protected function initialize(): void
    {
    }

    // ── 统一 JSON 响应 ──────────────────────────────────────────────

    /**
     * 成功响应。
     */
    protected function success(mixed $data = null, string $msg = 'success', int $code = 200): JsonResponse
    {
        return new JsonResponse([
            'errno' => 0,
            'code'  => $code,
            'msg'   => $msg,
            'data'  => $data,
        ], $code);
    }

    /**
     * 失败响应。
     *
     * HTTP 状态码固定为 200，业务错误码通过 body 中的 code 字段传递，
     * 以便前端 AJAX 统一走 success 回调处理。
     */
    protected function fail(string $msg = 'fail', int $code = 400): JsonResponse
    {
        return new JsonResponse([
            'errno' => 0,
            'code'  => $code,
            'msg'   => $msg,
            'data'  => null,
        ]);
    }
}
