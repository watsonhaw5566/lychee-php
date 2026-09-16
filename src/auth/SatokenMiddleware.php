<?php

declare(strict_types=1);

namespace Lychee\auth;

use Closure;
use Lychee\auth\exception\NotLoginException;
use Lychee\auth\exception\TokenInvalidException;
use Lychee\http\JsonResponse;
use Lychee\http\MiddlewareInterface;
use Lychee\http\Request;
use Lychee\http\Response;

/**
 * SaToken 鉴权中间件。
 *
 * 校验当前请求的登录态，未登录或 Token 无效时返回 401 JSON 响应。
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
        try {
            $this->saToken->checkLogin();
        } catch (NotLoginException | TokenInvalidException $e) {
            return new JsonResponse([
                'code'    => 401,
                'message' => $e->getMessage(),
            ], 401);
        }

        return $next($request);
    }
}
