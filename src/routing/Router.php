<?php

declare(strict_types=1);

namespace Lychee\routing;

use ReflectionClass;
use ReflectionMethod;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 基于 Attribute 的路由收集与分发器。
 */
class Router
{
    /**
     * 资源路由默认动作映射。
     *
     * 当控制器标注 #[Resource] 时，以下方法（若存在且为 public）
     * 将被自动注册为路由，无需再为每个方法添加 #[Route]。
     *
     * @var array<string, array{methods: list<string>, path: string}>
     */
    private const RESOURCE_ACTIONS = [
        'index'        => ['methods' => ['GET'],             'path' => '/'],
        'save'         => ['methods' => ['POST'],            'path' => '/'],
        'read'         => ['methods' => ['GET'],             'path' => '/{id}'],
        'update'       => ['methods' => ['PUT', 'PATCH'],    'path' => '/{id}'],
        'delete'       => ['methods' => ['DELETE'],          'path' => '/{id}'],
        'batch_delete' => ['methods' => ['DELETE'],          'path' => '/'],
    ];
    /** @var array<int, array{method:string, path:string, pattern:string, controller:class-string, action:string, middlewares:array<class-string>}> */
    private array $routes = [];

    /** @var array<string, string> */
    private array $namedRoutes = [];

    /**
     * @param class-string $controllerClass
     */
    public function registerController(string $controllerClass): void
    {
        $ref = new ReflectionClass($controllerClass);

        $prefix     = $this->resolvePrefix($ref);
        $isResource = !empty($ref->getAttributes(Resource::class));

        $classMiddlewares = $this->collectMiddlewares($ref);

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $routeAttrs = $method->getAttributes(Route::class);
            if (empty($routeAttrs)) {
                continue;
            }

            $route             = $routeAttrs[0]->newInstance();
            $path              = $this->joinPath($prefix, $route->path);
            $methodMiddlewares = $this->resolveMethodMiddlewares($method, $classMiddlewares);

            $this->routes[] = [
                'method'      => strtoupper($route->method),
                'path'        => $path,
                'pattern'     => $this->compilePattern($path),
                'controller'  => $controllerClass,
                'action'      => $method->getName(),
                'middlewares' => $methodMiddlewares,
            ];

            if ($route->name !== '') {
                $this->namedRoutes[$route->name] = $path;
            }
        }

        if ($isResource) {
            $this->registerResourceRoutes($ref, $controllerClass, $prefix, $classMiddlewares);
        }
    }

    /**
     * 为标注 #[Resource] 的控制器自动注册资源路由。
     *
     * 仅处理未显式声明 #[Route] 的方法，显式声明优先。
     *
     * @param class-string $controllerClass
     * @param array<class-string> $classMiddlewares
     */
    private function registerResourceRoutes(
        ReflectionClass $ref,
        string $controllerClass,
        string $prefix,
        array $classMiddlewares,
    ): void {
        foreach (self::RESOURCE_ACTIONS as $action => $definition) {
            if (!$ref->hasMethod($action)) {
                continue;
            }

            $method = $ref->getMethod($action);
            if (!$method->isPublic()) {
                continue;
            }

            // 已显式声明 #[Route] 的方法优先，跳过自动注册
            if (!empty($method->getAttributes(Route::class))) {
                continue;
            }

            $path        = $this->joinPath($prefix, $definition['path']);
            $middlewares = $this->resolveMethodMiddlewares($method, $classMiddlewares);

            foreach ($definition['methods'] as $httpMethod) {
                $this->routes[] = [
                    'method'      => $httpMethod,
                    'path'        => $path,
                    'pattern'     => $this->compilePattern($path),
                    'controller'  => $controllerClass,
                    'action'      => $action,
                    'middlewares' => $middlewares,
                ];
            }
        }
    }

    public function registerDirectory(string $directory, string $namespace): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($directory) + 1);
            $relative = str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            $class    = $namespace . '\\' . substr($relative, 0, -4);

            if (class_exists($class)) {
                $this->registerController($class);
            }
        }
    }

    public function dispatch(string $method, string $path): RouteMatch
    {
        $method = strtoupper($method);
        $path   = '/' . trim($path, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $path, $matches)) {
                $params = array_filter(
                    $matches,
                    fn ($k) => is_string($k),
                    ARRAY_FILTER_USE_KEY
                );

                return new RouteMatch(
                    controller: $route['controller'],
                    action: $route['action'],
                    params: $params,
                    middlewares: $route['middlewares'],
                );
            }
        }

        throw new RouteNotFoundException("No route found for [{$method}] {$path}");
    }

    /**
     * 获取所有已注册的路由。
     *
     * @return array<int, array{method:string, path:string, pattern:string, controller:class-string, action:string, middlewares:array<class-string>}>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * 解析控制器类的路由前缀。
     *
     * 优先使用 #[Resource] 注解，其次回退到类级 #[Route] 注解。
     */
    private function resolvePrefix(ReflectionClass $ref): string
    {
        $resourceAttrs = $ref->getAttributes(Resource::class);
        if (!empty($resourceAttrs)) {
            return $resourceAttrs[0]->newInstance()->path;
        }

        $routeAttrs = $ref->getAttributes(Route::class);
        if (!empty($routeAttrs)) {
            return $routeAttrs[0]->newInstance()->path;
        }

        return '';
    }

    private function compilePattern(string $path): string
    {
        $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $path);

        return '#^' . $pattern . '$#';
    }

    private function joinPath(string $prefix, string $path): string
    {
        return '/' . trim($prefix . '/' . trim($path, '/'), '/');
    }

    /**
     * @param ReflectionClass|ReflectionMethod $reflector
     * @return array<class-string>
     */
    private function collectMiddlewares(object $reflector): array
    {
        $middlewares = [];
        foreach ($reflector->getAttributes(Middleware::class) as $attr) {
            $value = $attr->newInstance()->middleware;

            if (is_array($value)) {
                foreach ($value as $m) {
                    $middlewares[] = $m;
                }
            } else {
                $middlewares[] = $value;
            }
        }

        return $middlewares;
    }

    /**
     * 解析方法最终生效的中间件列表。
     *
     * 合并类级与方法级中间件；若方法标注 #[WithoutMiddleware]，
     * 则从类级中间件中排除指定（或全部）中间件。
     *
     * @param array<class-string> $classMiddlewares
     * @return array<class-string>
     */
    private function resolveMethodMiddlewares(ReflectionMethod $method, array $classMiddlewares): array
    {
        $methodMiddlewares = $this->collectMiddlewares($method);

        $excludeAttrs = $method->getAttributes(WithoutMiddleware::class);
        if (!empty($excludeAttrs)) {
            $exclude = $excludeAttrs[0]->newInstance()->middleware;
            if (is_string($exclude)) {
                $exclude = [$exclude];
            }

            if (empty($exclude)) {
                $classMiddlewares = [];
            } else {
                $classMiddlewares = array_values(array_diff($classMiddlewares, $exclude));
            }
        }

        return array_merge($classMiddlewares, $methodMiddlewares);
    }
}
