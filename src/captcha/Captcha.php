<?php

declare(strict_types=1);

namespace Lychee\captcha;

use Lychee\captcha\driver\EmailDriver;
use Lychee\captcha\driver\ImageDriver;
use Lychee\captcha\driver\SmsDriver;
use Lychee\captcha\exception\CaptchaException;
use Lychee\config\Config;
use Lychee\container\Container;
use think\CacheManager;

/**
 * 验证码核心类。
 *
 * 统一管理验证码的生成、存储、校验与作废。
 * 验证码存储在缓存中，带 TTL 与失败次数限制，校验成功后立即作废（防重放）。
 *
 * 支持的驱动类型：
 *   - image：图形验证码，返回 PNG 二进制
 *   - sms：  短信验证码，调用短信网关发送
 *   - email：邮箱验证码，调用邮件服务发送
 */
class Captcha
{
    /** @var array<string, mixed> 默认配置 */
    protected array $config = [
        'store'        => null,
        'expire'       => 300,
        'max_attempts' => 5,
        'prefix'       => 'captcha:',
        // 驱动配置
        'image'        => [
            'chars'     => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
            'length'    => 4,
            'width'     => 120,
            'height'    => 40,
            'font_size' => 20,
        ],
        'sms'          => [
            'length' => 6,
            'send'   => null,
        ],
        'email'        => [
            'length'  => 6,
            'subject' => '您的验证码',
            'send'    => null,
        ],
    ];

    /** @var array<string, CaptchaInterface> 已实例化的驱动 */
    private array $drivers = [];

    public function __construct(protected Container $app)
    {
        $this->config = $this->getConfig();
    }

    /**
     * 生成验证码。
     *
     * @param  string $type 驱动类型：image / sms / email
     * @param  string $key  关联标识（captcha_id / 手机号 / 邮箱）
     * @return mixed        驱动的 render 返回值（图片二进制 / 发送结果）
     *
     * @throws CaptchaException 驱动不存在时抛出
     */
    public function generate(string $type, string $key): mixed
    {
        $driver = $this->driver($type);
        $code   = $driver->generateCode();

        $cache = $this->cache();
        $ckey  = $this->cacheKey($type, $key);

        $cache->set($ckey, [
            'code'     => $code,
            'attempts' => 0,
        ], (int) $this->config['expire']);

        return $driver->render($code, $key);
    }

    /**
     * 校验验证码。
     *
     * 校验成功后立即作废（防重放）；失败时累加尝试次数，超限自动作废。
     *
     * @param  string $type 驱动类型
     * @param  string $key  关联标识
     * @param  string $code 用户输入的验证码
     * @return bool         校验是否通过
     */
    public function verify(string $type, string $key, string $code): bool
    {
        $cache = $this->cache();
        $ckey  = $this->cacheKey($type, $key);
        /** @var array{code: string, attempts: int}|null $data */
        $data = $cache->get($ckey);

        if ($data === null || !is_array($data)) {
            return false;
        }

        $maxAttempts = (int) $this->config['max_attempts'];

        if (($data['attempts'] ?? 0) >= $maxAttempts) {
            $cache->delete($ckey);

            return false;
        }

        if (strcasecmp((string) $data['code'], $code) !== 0) {
            $data['attempts'] = ($data['attempts'] ?? 0) + 1;
            $cache->set($ckey, $data, (int) $this->config['expire']);

            return false;
        }

        $cache->delete($ckey);

        return true;
    }

    /**
     * 手动作废验证码。
     */
    public function clear(string $type, string $key): void
    {
        $this->cache()->delete($this->cacheKey($type, $key));
    }

    /**
     * 获取或创建驱动实例。
     */
    private function driver(string $type): CaptchaInterface
    {
        if (isset($this->drivers[$type])) {
            return $this->drivers[$type];
        }

        /** @var array<string, mixed> $driverConfig */
        $driverConfig = $this->config[$type] ?? [];

        // 将 send 配置（类名字符串）解析为 [$instance, 'send'] callable
        if (array_key_exists('send', $driverConfig) && is_string($driverConfig['send'])) {
            $instance             = $this->app->make($driverConfig['send']);
            $driverConfig['send'] = [$instance, 'send'];
        }

        $driver = match ($type) {
            'image' => new ImageDriver($driverConfig),
            'sms'   => new SmsDriver($driverConfig),
            'email' => new EmailDriver($driverConfig),
            default => throw new CaptchaException("未知的验证码驱动类型：{$type}"),
        };

        $this->drivers[$type] = $driver;

        return $driver;
    }

    /**
     * 获取缓存驱动。
     */
    private function cache()
    {
        $store = $this->config['store'];

        /** @var CacheManager $cacheManager */
        $cacheManager = $this->app->get('cache');

        return $store ? $cacheManager->store($store) : $cacheManager->store();
    }

    /**
     * 生成缓存键。
     */
    private function cacheKey(string $type, string $key): string
    {
        $prefix = (string) $this->config['prefix'];

        return "{$prefix}{$type}:{$key}";
    }

    /**
     * 读取并合并配置。
     */
    private function getConfig(): array
    {
        /** @var Config $config */
        $config        = $this->app->get('config');
        $captchaConfig = $config->get('captcha', []);
        if (!is_array($captchaConfig)) {
            $captchaConfig = [];
        }

        $merged = [];
        foreach (array_merge($this->config, $captchaConfig) as $k => $v) {
            $merged[(string) $k] = $v;
        }

        return $merged;
    }
}
