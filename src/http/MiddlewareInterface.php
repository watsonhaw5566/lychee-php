<?php

declare(strict_types=1);

namespace Lychee\http;

use Closure;

/**
 * 中间件接口。
 */
interface MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response;
}
