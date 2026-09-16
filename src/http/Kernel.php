<?php

declare(strict_types=1);

namespace Lychee\http;

use Lychee\container\Container;
use Lychee\routing\RouteMatch;
use Lychee\routing\RouteNotFoundException;
use Lychee\routing\Router;
use Lychee\view\ExceptionRenderer;
use think\Validate as Validator;
use ReflectionClass;
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
        private readonly Validator $validator,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $route = $this->router->dispatch($request->method, $request->path);
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

            if ($this->isDto($className)) {
                return $this->resolveDto($className, $request);
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

        if (array_key_exists($name, $request->all())) {
            return $this->castScalar($request->all()[$name], $type);
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

    private function isDto(string $class): bool
    {
        return class_exists($class)
            && !is_subclass_of($class, Request::class)
            && (new ReflectionClass($class))->isInstantiable();
    }

    private function resolveDto(string $dtoClass, Request $request): object
    {
        $ref         = new ReflectionClass($dtoClass);
        $constructor = $ref->getConstructor();

        if ($constructor === null) {
            $dto = new $dtoClass();
        } else {
            $data = array_merge($request->all(), $this->routeParamsArray($request));
            $args = [];
            foreach ($constructor->getParameters() as $param) {
                $name = $param->getName();
                $type = $param->getType();

                if (array_key_exists($name, $data)) {
                    $args[] = $this->castScalar($data[$name], $type);
                } elseif ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                } elseif ($param->allowsNull()) {
                    $args[] = null;
                } else {
                    throw new RuntimeException("Missing field '{$name}' for DTO {$dtoClass}.");
                }
            }
            $dto = $ref->newInstanceArgs($args);
        }

        $this->validator->validate($dto);

        return $dto;
    }

    private function routeParamsArray(Request $request): array
    {
        $ref  = new ReflectionClass($request);
        $prop = $ref->getProperty('routeParams');
        $prop->setAccessible(true);

        return $prop->getValue($request);
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
        if ($e instanceof \Lychee\validation\ValidationException) {
            $status = $e->getCode() ?: 400;

            return json([
                'code' => $status,
                'msg'  => $e->getMessage(),
            ], $status);
        }

        $status = 500;
        if ($e instanceof RouteNotFoundException) {
            $status = 404;
        } elseif ($e instanceof HttpException) {
            $status = $e->getStatusCode();
        }

        $debug = (bool) config('app.debug', env('APP_DEBUG', false));

        // 浏览器请求渲染异常页；JSON 请求返回结构化错误
        if ($this->wantsHtml($request)) {
            if ($debug) {
                $renderer = new ExceptionRenderer(
                    cachePath: (string) app('path.runtime') . 'twig'
                );

                $html = $renderer->render(
                    status: $status,
                    e: $e,
                    method: $request->method,
                    url: $request->path,
                );

                return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
            }

            // 非调试模式返回空白页
            return new Response('', $status, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        // JSON 请求
        if ($debug) {
            return new JsonResponse([
                'code'  => $status,
                'msg'   => $e->getMessage(),
                'type'  => $e::class,
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ], $status);
        }

        return new JsonResponse([
            'code' => $status,
            'msg'  => $this->statusText($status),
        ], $status);
    }

    /**
     * 判断请求是否期望 HTML 响应（浏览器访问）。
     */
    private function wantsHtml(Request $request): bool
    {
        $accept = $request->header('Accept', '');

        // 显式请求 JSON 时不返回 HTML
        if (str_contains($accept, 'application/json')) {
            return false;
        }

        return str_contains($accept, 'text/html') || $accept === '' || str_contains($accept, '*/*');
    }

    /**
     * 根据状态码返回通用错误描述。
     */
    private function statusText(int $status): string
    {
        return match ($status) {
            404     => 'Not Found',
            403     => 'Forbidden',
            401     => 'Unauthorized',
            405     => 'Method Not Allowed',
            422     => 'Unprocessable Entity',
            default => 'Server Error',
        };
    }
}
