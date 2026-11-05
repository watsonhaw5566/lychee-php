<?php

declare(strict_types=1);

namespace Lychee\auth;

/**
 * SaToken 认证接口。
 */
interface SatokenInterface
{
    public function createToken(): string;

    public function validateTokenFormat(string $token): bool;

    public function login(int $loginId, array $extra = []): string;

    /**
     * 在闭包内设置当前 token 上下文。
     *
     * 适用于非 HTTP 请求场景（如 WebSocket、CLI 队列），此时无 Request 对象可供
     * TokenReader 读取。闭包内所有未显式传 token 的方法（getCurrentLoginId、
     * hasPermission 等）都会自动使用该 token；闭包执行结束后恢复原状态。
     *
     * @param  string|null  $token
     * @param  callable     $callback
     * @return mixed        闭包的返回值
     */
    public function withToken(?string $token, callable $callback): mixed;

    public function logout(?string $token = null): bool;

    public function kickout(int $id): bool;

    public function kickoutByToken(string $token): bool;

    public function isLogin(?string $token = null): bool;

    public function checkLogin(?string $token = null): void;

    public function getCurrentLoginId(?string $token = null): int;

    public function getTokenExpireTime(?string $token = null): int;

    public function getTokenRemainingTime(?string $token = null): int;

    /** @return array<string, mixed> */
    public function getTokenInfo(?string $token = null): array;

    /** @return array<string, mixed> */
    public function getExtra(?string $token = null): array;

    /** @param array<string, mixed> $extra */
    public function setExtra(?string $token = null, array $extra = []): bool;

    /**
     * 判断当前登录用户是否拥有指定权限。
     *
     * @param string $permission 权限标识，如 'user.edit'
     */
    public function hasPermission(string $permission): bool;

    /**
     * 判断当前登录用户是否拥有指定权限中的任意一个（OR 逻辑）。
     *
     * @param string ...$permissions 权限标识列表
     */
    public function hasAnyPermission(string ...$permissions): bool;

    /**
     * 校验当前登录用户是否拥有指定权限，不通过则抛出 NoPermissionException。
     *
     * @param string $permission 权限标识
     * @throws \Lychee\auth\exception\NoPermissionException
     */
    public function checkPermission(string $permission): void;

    /**
     * 校验当前登录用户是否拥有任意一个权限，全部不通过则抛出 NoPermissionException。
     *
     * @param string ...$permissions 权限标识列表
     * @throws \Lychee\auth\exception\NoPermissionException
     */
    public function checkAnyPermission(string ...$permissions): void;
}
