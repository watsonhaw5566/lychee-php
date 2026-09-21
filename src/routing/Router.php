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
    private string $routePrefix;
    /** @var array<int, array{method:string, path:string, pattern:string, controller:class-string, action:string, middlewares:array<class-string>, cache:?int}> */
    private array $routes = [];

    /** @var array<string, string> */
    private array $namedRoutes = [];

    public function __construct(string $routePrefix = '')
    {
        $this->routePrefix = trim($routePrefix, '/');
    }

    /**
     * @param class-string $controllerClass
     */
    public function registerController(string $controllerClass): void
    {
        $ref = new ReflectionClass($controllerClass);

        $prefix      = $this->resolvePrefix($ref);
        $classPrefix = $this->resolveClassPrefixOverride($ref);
        $isResource  = !empty($ref->getAttributes(Resource::class));
        $classCache  = $this->resolveClassCache($ref);

        $classMiddlewares = $this->collectMiddlewares($ref);

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $routeAttrs = $method->getAttributes(Route::class);
            if (empty($routeAttrs)) {
                continue;
            }

            $route             = $routeAttrs[0]->newInstance();
            $globalPrefix      = $route->prefix ?? $classPrefix ?? $this->routePrefix;
            $path              = $this->joinPath($prefix, $route->path, $globalPrefix);
            $methodMiddlewares = $this->resolveMethodMiddlewares($method, $classMiddlewares);
            // 方法级 cache 优先，否则回退到类级
            $cache             = $route->cache ?? $classCache;

            $this->routes[] = [
                'method'      => strtoupper($route->method),
                'path'        => $path,
                'pattern'     => $this->compilePattern($path),
                'controller'  => $controllerClass,
                'action'      => $method->getName(),
                'middlewares' => $methodMiddlewares,
                'cache'       => $cache,
            ];

            if ($route->name !== '') {
                $this->namedRoutes[$route->name] = $path;
            }
        }

        if ($isResource) {
            $this->registerResourceRoutes($ref, $controllerClass, $prefix, $classPrefix, $classMiddlewares, $classCache);
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
        ?string $classPrefix,
        array $classMiddlewares,
        ?int $classCache,
    ): void {
        $globalPrefix = $classPrefix ?? $this->routePrefix;

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

            $path        = $this->joinPath($prefix, $definition['path'], $globalPrefix);
            $middlewares = $this->resolveMethodMiddlewares($method, $classMiddlewares);

            foreach ($definition['methods'] as $httpMethod) {
                $this->routes[] = [
                    'method'      => $httpMethod,
                    'path'        => $path,
                    'pattern'     => $this->compilePattern($path),
                    'controller'  => $controllerClass,
                    'action'      => $action,
                    'middlewares' => $middlewares,
                    'cache'       => $classCache,
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
                    cache: $route['cache'],
                );
            }
        }

        throw new RouteNotFoundException("No route found for [{$method}] {$path}");
    }

    /**
     * 获取所有已注册的路由。
     *
     * @return array<int, array{method:string, path:string, pattern:string, controller:class-string, action:string, middlewares:array<class-string>, cache:?int}>
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

    /**
     * 解析控制器类级别的全局前缀覆盖。
     *
     * 优先使用 #[Resource] 的 prefix，其次回退到类级 #[Route] 的 prefix。
     * 返回 null 表示未设置覆盖，应使用全局 route_prefix。
     */
    private function resolveClassPrefixOverride(ReflectionClass $ref): ?string
    {
        $resourceAttrs = $ref->getAttributes(Resource::class);
        if (!empty($resourceAttrs)) {
            return $resourceAttrs[0]->newInstance()->prefix;
        }

        $routeAttrs = $ref->getAttributes(Route::class);
        if (!empty($routeAttrs)) {
            return $routeAttrs[0]->newInstance()->prefix;
        }

        return null;
    }

    /**
     * 解析控制器类级别的缓存 TTL。
     *
     * 优先使用 #[Resource] 的 cache，其次回退到类级 #[Route] 的 cache。
     * 返回 null 表示未设置缓存。
     */
    private function resolveClassCache(ReflectionClass $ref): ?int
    {
        $resourceAttrs = $ref->getAttributes(Resource::class);
        if (!empty($resourceAttrs)) {
            return $resourceAttrs[0]->newInstance()->cache;
        }

        $routeAttrs = $ref->getAttributes(Route::class);
        if (!empty($routeAttrs)) {
            return $routeAttrs[0]->newInstance()->cache;
        }

        return null;
    }

    private function compilePattern(string $path): string
    {
        $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $path);

        return '#^' . $pattern . '$#';
    }

    /**
     * 拼接路由路径。
     *
     * @param string $prefix      控制器级前缀（来自 #[Resource] 或类级 #[Route] 的 path）
     * @param string $path        方法级路径
     * @param string $globalPrefix 全局前缀（route_prefix 或被 prefix 参数覆盖后的值）
     */
    private function joinPath(string $prefix, string $path, string $globalPrefix = ''): string
    {
        $path = '/' . trim($prefix . '/' . trim($path, '/'), '/');

        if ($globalPrefix !== '') {
            $path = '/' . trim($globalPrefix . $path, '/');
        }

        return $path;
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
