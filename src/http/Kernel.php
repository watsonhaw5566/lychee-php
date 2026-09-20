<?php

declare(strict_types=1);

namespace Lychee\http;

use Lychee\container\Container;
use Lychee\routing\RouteMatch;
use Lychee\routing\RouteNotFoundException;
use Lychee\routing\Router;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use RuntimeException;
use Throwable;

/**
 * HTTP 内核：请求 → 路由 → 中间件 → 控制器 → 响应。
 */
class Kernel
{
    public function __construct(
        private readonly Router $router,
        private readonly Container $container,
        private readonly MiddlewarePipeline $pipeline,
        private readonly ExceptionHandler $exceptionHandler,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $route = $this->router->dispatch($request->method, $request->path);
        } catch (RouteNotFoundException $e) {
            // 跨域预检（OPTIONS）通常不会逐路由注册：未命中路由时仍交给全局
            // 中间件管道处理，由 CORS 中间件短路返回预检响应
            if ($request->method === 'OPTIONS') {
                return $this->handlePreflight($request);
            }

            return $this->exceptionResponse($request, $e);
        } catch (Throwable $e) {
            return $this->exceptionResponse($request, $e);
        }

        $request = $request->withRouteParams($route->params);

        // 合并全局中间件与路由中间件：全局中间件先于路由中间件执行
        $globalMiddlewares = (array) config('middleware', []);
        $middlewares       = array_merge($globalMiddlewares, $route->middlewares);

        try {
            return $this->pipeline->handle(
                $request,
                $middlewares,
                function (Request $req) use ($route): Response {
                    return $this->callController($req, $route);
                }
            );
        } catch (Throwable $e) {
            return $this->exceptionResponse($request, $e);
        }
    }

    /**
     * 处理未命中路由的 OPTIONS 预检请求。
     *
     * 仅经过全局中间件管道（CORS 等跨域中间件通常注册于此），
     * 管道终点返回 204 No Content。
     */
    private function handlePreflight(Request $request): Response
    {
        $globalMiddlewares = (array) config('middleware', []);

        try {
            return $this->pipeline->handle(
                $request,
                $globalMiddlewares,
                static fn (Request $_request): Response => new Response('', 204),
            );
        } catch (Throwable $e) {
            return $this->exceptionResponse($request, $e);
        }
    }

    private function callController(Request $request, RouteMatch $route): Response
    {
        $controller = $this->container->get($route->controller);

        // 中间件已执行完毕，此时调用 initialize 可拿到完整请求上下文
        if (method_exists($controller, 'initialize')) {
            $init = new ReflectionMethod($controller, 'initialize');
            $init->setAccessible(true);
            $init->invoke($controller);
        }

        $method = new ReflectionMethod($controller, $route->action);

        $args = [];
        foreach ($method->getParameters() as $param) {
            $args[] = $this->resolveArgument($param, $request, $controller);
        }

        $result = $method->invokeArgs($controller, $args);

        if ($result instanceof Response) {
            return $result;
        }

        return new JsonResponse($result);
    }

    private function resolveArgument(
        ReflectionParameter $param,
        Request $request,
        object $controller,
    ): mixed {
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $className = $type->getName();

            if ($className === Request::class || is_subclass_of($className, Request::class)) {
                return $request;
            }

            if ($this->container->has($className)) {
                return $this->container->get($className);
            }
        }

        $name = $param->getName();

        if ($request->routeParam($name) !== null) {
            $value = $request->routeParam($name);

            return $this->castScalar($value, $type);
        }

        if (array_key_exists($name, $request->param())) {
            return $this->castScalar($request->param()[$name], $type);
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($param->allowsNull()) {
            return null;
        }

        throw new RuntimeException(
            "Cannot resolve parameter \${$name} of " . $controller::class . '::' . $param->getDeclaringFunction()->getName()
        );
    }

    private function castScalar(mixed $value, ?ReflectionType $type): mixed
    {
        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        return match ($type->getName()) {
            'int'    => (int) $value,
            'float'  => (float) $value,
            'bool'   => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
            'string' => (string) $value,
            'array'  => (array) $value,
            default  => $value,
        };
    }

    private function exceptionResponse(Request $request, Throwable $e): Response
    {
        $this->exceptionHandler->report($e);

        return $this->exceptionHandler->render($request, $e);
    }
}
