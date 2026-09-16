<?php

declare(strict_types=1);

namespace Tests\stub\app\middleware;

use Lychee\http\HttpException;
use Lychee\http\MiddlewareInterface;
use Lychee\http\Request;
use Lychee\http\Response;
use Closure;

/**
 * 鉴权中间件示例。
 *
 * 通过 #[Middleware(AuthMiddleware::class)] 挂载。
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Token');

        if ($token === null || $token === '') {
            throw new HttpException(401, 'Unauthorized.');
        }

        return $next($request);
    }
}
