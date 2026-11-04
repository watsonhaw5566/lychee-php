<?php

declare(strict_types=1);

namespace Lychee\auth;

use Closure;
use Lychee\auth\exception\NoPermissionException;
use Lychee\http\JsonResponse;
use Lychee\http\MiddlewareInterface;
use Lychee\http\Request;
use Lychee\http\Response;
use Lychee\routing\ResourceController;
use ReflectionClass;
use ReflectionMethod;

/**
 * 功能权限拦截中间件。
 *
 * 读取控制器方法和类上的 #[Permission] 注解，
 * 通过 SaToken::checkAnyPermission() 校验当前用户是否拥有所需权限。
 *
 * 优先级：方法级 > 类级。方法上声明了 #[Permission] 则忽略类级声明。
 * 未标注 #[Permission] 的方法默认放行（渐进式迁移）。
 *
 * 用法：在全局中间件或路由中间件中注册即可。
 * 注意：需在 SatokenMiddleware 之后注册，确保 loginId 已设置。
 */
class PermissionMiddleware implements MiddlewareInterface
{
    /** CRUD 方法名 → 操作标识 映射 */
    private const CRUD_MAP = [
        'index'  => 'list',
        'save'   => 'create',
        'read'   => 'read',
        'update' => 'edit',
        'delete' => 'delete',
    ];
    public function __construct(
        private readonly SaToken $saToken,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $controller = $request->getRouteController();
        $action     = $request->getRouteAction();

        if ($controller === '' || $action === '') {
            return $next($request);
        }

        $permissions = $this->resolvePermissions($controller, $action);

        // 未标注 Permission 注解，默认放行
        if ($permissions === []) {
            return $next($request);
        }

        try {
            $this->saToken->checkAnyPermission(...$permissions);
        } catch (NoPermissionException $e) {
            return new JsonResponse([
                'errno' => 0,
                'code'  => 403,
                'msg'   => $e->getMessage(),
                'data'  => null,
            ], 403);
        }

        return $next($request);
    }

    /**
     * 解析控制器方法所需的权限列表。
     *
     * 优先级：方法级 Attribute > 类级 Attribute > ResourceController CRUD 自动推断。
     *
     * @param class-string $controller
     * @return array<int, string>
     */
    protected function resolvePermissions(string $controller, string $action): array
    {
        if (!class_exists($controller)) {
            return [];
        }

        // 方法级 Attribute
        if (method_exists($controller, $action)) {
            $method = new ReflectionMethod($controller, $action);
            $attrs  = $method->getAttributes(Permission::class);
            if ($attrs !== []) {
                return $attrs[0]->newInstance()->permissions;
            }
        }

        // 类级 Attribute
        $class = new ReflectionClass($controller);
        $attrs = $class->getAttributes(Permission::class);
        if ($attrs !== []) {
            return $attrs[0]->newInstance()->permissions;
        }

        // ResourceController CRUD 自动推断
        return $this->inferResourcePermission($controller, $action);
    }

    /**
     * ResourceController 子类自动推断权限。
     *
     * 仅对 CRUD 五个方法生效，模块名从控制器短类名推断：
     * UserController → user, BillCustomerController → bill_customer
     *
     * @return array<int, string>
     */
    protected function inferResourcePermission(string $controller, string $action): array
    {
        if (!is_subclass_of($controller, ResourceController::class)) {
            return [];
        }

        $operation = self::CRUD_MAP[$action] ?? null;
        if ($operation === null) {
            return [];
        }

        $module = $this->guessModuleName($controller);

        return ["{$module}.{$operation}"];
    }

    /**
     * 从控制器类名推断模块标识。
     *
     * UserController → user
     * BillCustomerController → bill_customer
     */
    protected function guessModuleName(string $controller): string
    {
        $short = (new ReflectionClass($controller))->getShortName();
        $name  = preg_replace('/Controller$/', '', $short);

        // PascalCase → snake_case
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
