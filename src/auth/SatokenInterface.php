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
}
