<?php

declare(strict_types=1);

namespace Lychee\captcha\exception;

use Lychee\http\HttpException;

/**
 * 验证码异常，对应 HTTP 400。
 *
 * 在验证码生成/校验失败时抛出，由全局异常处理器渲染为 400 响应。
 */
class CaptchaException extends HttpException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct(400, $message, $code, $previous);
    }
}
