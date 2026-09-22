<?php

declare(strict_types=1);

namespace Lychee\http;

use Lychee\container\Container;
use think\db\Query;
use think\Model;

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

    /**
     * 分页响应。
     *
     * 传入 Query / Model 时自动执行 count 统计与分页查询；
     * 传入数组时直接作为当页数据返回（total 为 0）。
     *
     * @param mixed $items 查询构建器、模型或当页数据数组
     * @param int $page 当前页码
     * @param int $pageSize 每页条数（自动限制在 1~200）
     * @param string $message 提示信息
     * @param int $httpStatus HTTP 状态码
     */
    protected function paginate(
        mixed  $items = [],
        int    $page = 1,
        int    $pageSize = 10,
        string $message = 'success',
        int    $httpStatus = 200,
    ): JsonResponse {
        $total = 0;

        // 如果传入的是查询构建器对象
        if ($items instanceof Query || $items instanceof Model) {
            $query    = $items instanceof Model ? $items->db() : $items;
            $pageSize = max(1, min(200, $pageSize)); // 限制每页记录数范围

            // 自动计算总数
            $total = $query->count();

            // 获取当前页数据
            $items = $query->page($page, $pageSize)->select()->toArray();
        }

        // 确保 $items 是数组
        if (!is_array($items)) {
            $items = [];
        }

        return new JsonResponse([
            'errno' => 0,
            'code'  => $httpStatus,
            'msg'   => $message,
            'data'  => [
                'list'  => $items,
                'total' => $total,
            ],
        ], $httpStatus);
    }
}
