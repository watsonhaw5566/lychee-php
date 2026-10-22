<?php

declare(strict_types=1);

namespace Tests\stub\app\middleware;

use Closure;
use Lychee\http\MiddlewareInterface;
use Lychee\http\Request;
use Lychee\http\Response;

/**
 * 模拟认证中间件：将登录 ID 写入请求（同 SatokenMiddleware 行为）。
 *
 * 用于验证控制器中 request() 助手函数与中间件管道拿到的是同一实例。
 */
class SetLoginIdMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->setLoginId(42);

        return $next($request);
    }
}
