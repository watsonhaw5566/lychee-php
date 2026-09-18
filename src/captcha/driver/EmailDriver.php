<?php

declare(strict_types=1);

namespace Lychee\captcha\driver;

use Lychee\captcha\CaptchaInterface;

/**
 * 邮箱验证码驱动。
 *
 * 负责生成数字验证码，并通过邮件发送给用户。
 * 邮件发送通过 send 配置指定发送器类名，该类需实现 send(string $code, string $email, string $subject): bool 方法，
 * 由容器实例化（支持依赖注入）。留空则不实际发送（开发调试用）。
 */
class EmailDriver implements CaptchaInterface
{
    /** @var array<string, mixed> 配置 */
    private array $config;

    /**
     * @param array<string, mixed> $config 配置项：length / subject / send（发送闭包）
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'length'  => 6,
            'subject' => '您的验证码',
            'send'    => null,
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

        return (bool) $send($code, $key, (string) $this->config['subject']);
    }
}
