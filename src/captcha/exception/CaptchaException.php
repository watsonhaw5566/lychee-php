<?php

declare(strict_types=1);

namespace Lychee\captcha\exception;

use RuntimeException;

/**
 * 验证码异常。
 *
 * 在验证码生成/校验失败时抛出，HTTP 内核可捕获并返回 400 响应。
 */
class CaptchaException extends RuntimeException
{
}
