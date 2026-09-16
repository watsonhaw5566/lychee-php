<?php

declare(strict_types=1);

namespace Lychee\session;

use Closure;
use Lychee\http\MiddlewareInterface;
use Lychee\http\Request;
use Lychee\http\Response;

/**
 * Session 中间件。
 *
 * 在请求处理前启动会话，处理后保存会话并写入 Cookie。
 */
class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Session $session,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->session->start($request);

        $response = $next($request);

        $this->session->save($response);

        return $response;
    }
}
