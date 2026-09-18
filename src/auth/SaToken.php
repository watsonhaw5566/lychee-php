<?php

declare(strict_types=1);

namespace Lychee\auth;

use Lychee\auth\exception\NotLoginException;
use Lychee\auth\exception\TokenInvalidException;
use Lychee\config\Config;
use Ramsey\Uuid\Uuid;
use think\CacheManager;
use think\Container;

/**
 * SaToken 轻量级权限认证。
 *
 * 基于缓存实现 Token 登录态管理，支持多端登录、滑动续期、强制踢人。
 */
class SaToken implements SatokenInterface
{
    /** @var array<string, mixed> 默认配置 */
    protected array $config = [
        // 请求头中的 token 字段名（空则只认 Authorization: Bearer）
        'token_name'        => '',
        // Cookie 中的 token 字段名
        'token_cookie_name' => 'satoken',
        // Token 读取驱动：header / cookie / chain / 自定义类名
        // chain 表示先 header 后 cookie，兼顾 SPA 与传统页面跳转
        'token_reader'      => 'chain',
        'store'             => null,
        'timeout'           => 86400 * 7,
        'auto_renew'        => true,
        'renew_before'      => 3600,
        'max_login_count'   => 10,
    ];

    protected ?TokenReaderInterface $tokenReader = null;

    public function __construct(protected Container $app)
    {
    }

    protected function cache()
    {
        $config = $this->getConfig();
        $store  = $config['store'] ?? null;

        /** @var CacheManager $cacheManager */
        $cacheManager = $this->app->get('cache');

        return $store ? $cacheManager->store($store) : $cacheManager->store();
    }

    public function login(int $loginId, array $extra = []): string
    {
        $config        = $this->getConfig();
        $timeout       = (int) $config['timeout'];
        $maxLoginCount = isset($config['max_login_count']) ? (int) $config['max_login_count'] : 10;
        if ($maxLoginCount < 1) {
            $maxLoginCount = 1;
        }
        $cache = $this->cache();

        $token      = $this->createToken();
        $tokenKey   = "satoken:token:{$token}";
        $loginIdKey = "satoken:loginId:{$loginId}";

        $tokenList = $cache->get($loginIdKey);
        if (!is_array($tokenList)) {
            $tokenList = [];
        }
        $tokenList = array_values(array_filter($tokenList, 'is_string'));

        $tokenList = array_values(array_filter($tokenList, function ($t) use ($cache) {
            return is_string($t) && $t !== '' && is_array($cache->get("satoken:token:{$t}"));
        }));

        while (count($tokenList) >= $maxLoginCount) {
            $oldToken = array_shift($tokenList);
            if (is_string($oldToken) && $oldToken !== '') {
                $cache->delete("satoken:token:{$oldToken}");
            }
        }

        $tokenInfo = [
            'loginId'     => $loginId,
            'create_time' => time(),
            'expire_time' => time() + $timeout,
            'extra'       => $extra,
        ];
        $cache->set($tokenKey, $tokenInfo, $timeout);

        $tokenList[] = $token;
        $cache->set($loginIdKey, $tokenList, $timeout);

        return $token;
    }

    public function createToken(): string
    {
        return Uuid::uuid4()->toString();
    }

    /** @return array<string, mixed> */
    private function getConfig(): array
    {
        /** @var Config $config */
        $config        = $this->app->get('config');
        $satokenConfig = $config->get('satoken', []);
        if (!is_array($satokenConfig)) {
            $satokenConfig = [];
        }

        $merged = [];
        foreach (array_merge($this->config, $satokenConfig) as $key => $value) {
            $merged[(string) $key] = $value;
        }

        return $merged;
    }

    private function resolveToken(?string $token): ?string
    {
        if (empty($token)) {
            $token = $this->getToken();
        }

        return empty($token) ? null : $token;
    }

    /** @return array<string, mixed>|null */
    private function fetchTokenInfo(string $token): ?array
    {
        if (!$this->validateTokenFormat($token)) {
            return null;
        }

        $tokenInfo = $this->cache()->get("satoken:token:{$token}");

        return is_array($tokenInfo) ? $tokenInfo : null;
    }

    private function extractLoginId(array $tokenInfo): ?int
    {
        if (!isset($tokenInfo['loginId']) || !is_int($tokenInfo['loginId'])) {
            return null;
        }

        return $tokenInfo['loginId'];
    }

    /** @return array<string, mixed> */
    private function getValidTokenInfo(string $token): array
    {
        if (!$this->validateTokenFormat($token)) {
            throw new TokenInvalidException('无效的token格式');
        }

        $tokenInfo = $this->cache()->get("satoken:token:{$token}");
        if (!is_array($tokenInfo)) {
            throw new TokenInvalidException('无效的token');
        }

        return $tokenInfo;
    }

    private function extractLoginIdOrThrow(array $tokenInfo): int
    {
        $loginId = $this->extractLoginId($tokenInfo);
        if ($loginId === null) {
            throw new TokenInvalidException('token信息不完整');
        }

        return $loginId;
    }

    private function removeTokenFromLoginIdList(int $loginId, string $token): void
    {
        $cache      = $this->cache();
        $loginIdKey = "satoken:loginId:{$loginId}";
        $tokenList  = $cache->get($loginIdKey);

        if (!is_array($tokenList)) {
            return;
        }

        $tokenList = array_values(array_filter($tokenList, function ($t) use ($token) {
            return is_string($t) && $t !== '' && $t !== $token;
        }));

        if (count($tokenList) > 0) {
            $cache->set($loginIdKey, $tokenList, (int) $this->getConfig()['timeout']);
        } else {
            $cache->delete($loginIdKey);
        }
    }

    public function logout(?string $token = null): bool
    {
        $token = $this->resolveToken($token);
        if ($token === null) {
            return false;
        }

        $tokenInfo = $this->fetchTokenInfo($token);
        if ($tokenInfo === null) {
            return false;
        }

        $loginId = $this->extractLoginId($tokenInfo);
        if ($loginId === null) {
            return false;
        }

        $this->cache()->delete("satoken:token:{$token}");
        $this->removeTokenFromLoginIdList($loginId, $token);

        return true;
    }

    private function getToken(): ?string
    {
        return $this->getTokenReader()->read();
    }

    /**
     * 根据配置解析 TokenReader 实例。
     */
    protected function getTokenReader(): TokenReaderInterface
    {
        if ($this->tokenReader !== null) {
            return $this->tokenReader;
        }

        $config     = $this->getConfig();
        $reader     = $config['token_reader'] ?? 'chain';
        $tokenName  = (string) ($config['token_name'] ?? '');
        $cookieName = (string) ($config['token_cookie_name'] ?? 'satoken');

        if ($reader instanceof TokenReaderInterface) {
            return $this->tokenReader = $reader;
        }

        $reader = (string) $reader;

        $this->tokenReader = match ($reader) {
            'header' => new HeaderTokenReader($tokenName),
            'cookie' => new CookieTokenReader($cookieName),
            'chain'  => new ChainTokenReader(
                new HeaderTokenReader($tokenName),
                new CookieTokenReader($cookieName),
            ),
            default  => class_exists($reader) && is_subclass_of($reader, TokenReaderInterface::class)
                ? new $reader()
                : new ChainTokenReader(
                    new HeaderTokenReader($tokenName),
                    new CookieTokenReader($cookieName),
                ),
        };

        return $this->tokenReader;
    }

    public function validateTokenFormat(string $token): bool
    {
        if (strlen($token) !== 36) {
            return false;
        }

        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $token);
    }

    /** @param array<string, mixed> $tokenInfo */
    private function renewIfNeeded(string $token, array $tokenInfo): void
    {
        $config = $this->getConfig();
        if (empty($config['auto_renew'])) {
            return;
        }

        $timeout     = (int) $config['timeout'];
        $renewBefore = isset($config['renew_before']) ? (int) $config['renew_before'] : 3600;
        if ($renewBefore < 0) {
            $renewBefore = 3600;
        }

        $expireTime = isset($tokenInfo['expire_time']) ? (int) $tokenInfo['expire_time'] : 0;
        $remaining  = $expireTime - time();

        if ($remaining >= $renewBefore) {
            return;
        }

        $tokenInfo['expire_time'] = time() + $timeout;

        $this->cache()->set("satoken:token:{$token}", $tokenInfo, $timeout);
    }

    public function isLogin(?string $token = null): bool
    {
        $token = $this->resolveToken($token);
        if ($token === null) {
            return false;
        }

        $tokenInfo = $this->fetchTokenInfo($token);
        if ($tokenInfo === null) {
            return false;
        }

        if ($this->extractLoginId($tokenInfo) === null) {
            return false;
        }

        $this->renewIfNeeded($token, $tokenInfo);

        return true;
    }

    public function checkLogin(?string $token = null): void
    {
        if (empty($token)) {
            $token = $this->getToken();
            if (empty($token)) {
                throw new NotLoginException('未提供token');
            }
        }

        $tokenInfo = $this->getValidTokenInfo($token);
        $this->extractLoginIdOrThrow($tokenInfo);
        $this->renewIfNeeded($token, $tokenInfo);
    }

    public function getCurrentLoginId(?string $token = null): int
    {
        if (empty($token)) {
            $token = $this->getToken();
            if (empty($token)) {
                throw new NotLoginException('未提供token');
            }
        }

        $tokenInfo = $this->getValidTokenInfo($token);
        $loginId   = $this->extractLoginIdOrThrow($tokenInfo);
        $this->renewIfNeeded($token, $tokenInfo);

        return $loginId;
    }

    /** @return array<string, mixed> */
    public function getTokenInfo(?string $token = null): array
    {
        if (empty($token)) {
            $token = $this->getToken();
            if (empty($token)) {
                throw new NotLoginException('未提供token');
            }
        }

        $tokenInfo = $this->getValidTokenInfo($token);
        $this->renewIfNeeded($token, $tokenInfo);

        return $tokenInfo;
    }

    /** @return array<string, mixed> */
    public function getExtra(?string $token = null): array
    {
        $info = $this->getTokenInfo($token);

        if (!isset($info['extra']) || !is_array($info['extra'])) {
            return [];
        }

        $result = [];
        foreach ($info['extra'] as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /** @param array<string, mixed> $extra */
    public function setExtra(?string $token = null, array $extra = []): bool
    {
        $token = $this->resolveToken($token);
        if ($token === null) {
            return false;
        }

        $cache     = $this->cache();
        $tokenInfo = $this->fetchTokenInfo($token);
        if ($tokenInfo === null) {
            return false;
        }

        $remain = 0;
        if (!empty($tokenInfo['expire_time'])) {
            $remain = (int) $tokenInfo['expire_time'] - time();
        }
        if ($remain <= 0) {
            return false;
        }
        $tokenInfo['extra'] = $extra;
        $cache->set("satoken:token:{$token}", $tokenInfo, $remain);

        return true;
    }

    public function getTokenExpireTime(?string $token = null): int
    {
        $token = $this->resolveToken($token);
        if ($token === null) {
            return 0;
        }

        $tokenInfo = $this->fetchTokenInfo($token);
        if ($tokenInfo === null || empty($tokenInfo['expire_time'])) {
            return 0;
        }

        return (int) $tokenInfo['expire_time'];
    }

    public function getTokenRemainingTime(?string $token = null): int
    {
        $expire = $this->getTokenExpireTime($token);
        $remain = $expire - time();

        return max($remain, 0);
    }

    public function kickout(int $id): bool
    {
        $cache      = $this->cache();
        $loginIdKey = "satoken:loginId:{$id}";
        $tokenList  = $cache->get($loginIdKey);

        $deletedAny = false;
        if (is_array($tokenList)) {
            foreach ($tokenList as $oldToken) {
                if (is_string($oldToken) && $oldToken !== '') {
                    if ($cache->delete("satoken:token:{$oldToken}")) {
                        $deletedAny = true;
                    }
                }
            }
        } elseif (is_string($tokenList) && $tokenList !== '') {
            $cache->delete("satoken:token:{$tokenList}");
            $deletedAny = true;
        }

        $cache->delete($loginIdKey);

        return $deletedAny;
    }

    public function kickoutByToken(string $token): bool
    {
        return $this->logout($token);
    }
}
