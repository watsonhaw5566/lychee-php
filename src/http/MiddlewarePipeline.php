<?php

declare(strict_types=1);

namespace Lychee\http;

use Lychee\container\Container;
use Closure;

/**
 * 中间件管道（洋葱模型）。
 */
class MiddlewarePipeline
{
    public function __construct(
        private readonly Container $container,
    ) {
    }

    /**
     * @param array<class-string<MiddlewareInterface>> $middlewares
     */
    public function handle(Request $request, array $middlewares, Closure $destination): Response
    {
        $pipeline = array_reduce(
            array_reverse($middlewares),
            function (Closure $next, string $middlewareClass): Closure {
                return function (Request $request) use ($middlewareClass, $next): Response {
                    /** @var MiddlewareInterface $middleware */
                    $middleware = $this->container->get($middlewareClass);

                    return $middleware->handle($request, $next);
                };
            },
            $destination
        );

        return $pipeline($request);
    }
}
