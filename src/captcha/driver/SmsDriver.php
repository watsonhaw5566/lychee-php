<?php

declare(strict_types=1);

namespace Lychee\captcha\driver;

use Lychee\captcha\CaptchaInterface;

/**
 * 短信验证码驱动。
 *
 * 负责生成 6 位数字验证码，并调用短信网关发送。
 * 网关调用通过 send 配置指定发送器类名，该类需实现 send(string $code, string $phone): bool 方法，
 * 由容器实例化（支持依赖注入）。留空则不实际发送（开发调试用）。
 */
class SmsDriver implements CaptchaInterface
{
    /** @var array<string, mixed> 配置 */
    private array $config;

    /**
     * @param array<string, mixed> $config 配置项：length / send（发送闭包）
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'length' => 6,
            'send'   => null,
        ], $config);
    }

    public function generateCode(): string
    {
        $length = (int) $this->config['length'];
        $code   = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= random_int(0, 9);
        }

        return $code;
    }

    public function render(string $code, string $key): bool
    {
        /** @var callable|null $send */
        $send = $this->config['send'];

        if ($send === null) {
            return true;
        }

        return (bool) $send($code, $key);
    }
}
