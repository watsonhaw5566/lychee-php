<?php

declare(strict_types=1);

namespace Lychee\captcha;

/**
 * 验证码驱动接口。
 *
 * 不同类型的验证码（图形、短信、邮件）实现各自的生成与渲染逻辑，
 * 核心存储/校验由 Captcha 类统一管理。
 */
interface CaptchaInterface
{
    /**
     * 生成验证码明文字符串。
     *
     * @return string 验证码明文（由 Captcha 存储，不直接返回给客户端）
     */
    public function generateCode(): string;

    /**
     * 将验证码渲染为对外输出。
     *
     * - 图形验证码：返回 PNG 二进制字符串
     * - 短信验证码：调用短信网关发送，返回发送结果（成功/失败）
     *
     * @param  string $code  验证码明文
     * @param  string $key   关联标识（手机号 / 邮箱 / captcha_id）
     * @return mixed         驱动特定的输出
     */
    public function render(string $code, string $key): mixed;
}
