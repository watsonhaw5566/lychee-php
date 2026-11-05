<?php

declare(strict_types=1);

namespace Lychee\auth;

use Closure;
use Lychee\http\MiddlewareInterface;
use Lychee\http\Request;
use Lychee\http\Response;

/**
 * SaToken 鉴权中间件。
 *
 * 校验当前请求的登录态，未登录或 Token 无效时抛出对应异常，
 * 由全局异常处理器统一渲染为 401 响应（HTML 或 JSON 按请求自动判断）。
 * 通过 #[Middleware(SatokenMiddleware::class)] 按需挂载到控制器或方法。
 */
class SatokenMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SaToken $saToken,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // 未登录 / Token 无效会抛出 NotLoginException / TokenInvalidException
        // （均继承 HttpException，状态码 401），由全局 ExceptionHandler 统一渲染
        $this->saToken->checkLogin();

        // 将当前登录 ID 挂载到请求，供控制器 / 数据权限 trait 使用
        $request->setLoginId($this->saToken->getCurrentLoginId());

        return $next($request);
    }
}
