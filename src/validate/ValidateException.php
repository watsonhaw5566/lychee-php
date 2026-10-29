<?php

declare(strict_types=1);

namespace Lychee\validate;

use RuntimeException;

/**
 * 校验失败异常。
 *
 * 由 Validate::check() 在 failException(true) 时抛出；
 * HTTP Kernel 的 ExceptionHandler 捕获后统一返回 400 响应。
 *
 * 构造参数：
 *   - $error 可为字符串或 field => message 关联数组
 *   - $key   发生错误的字段名（可选，批量错误时留空）
 */
class ValidateException extends RuntimeException
{
    /**
     * @var string|array<string, string>
     */
    protected string|array $error;

    protected ?string $key;

    /**
     * @param string|array<string, string> $error
     */
    public function __construct(string|array $error, ?string $key = null, int $code = 0)
    {
        $this->error = $error;
        $this->key   = $key;

        $message = is_array($error) ? implode('；', $error) : $error;

        parent::__construct($message, $code);
    }

    /**
     * 获取原始错误信息（字符串或字段 => 信息数组）。
     *
     * @return string|array<string, string>
     */
    public function getError(): string|array
    {
        return $this->error;
    }

    /**
     * 获取发生错误的字段名。
     */
    public function getKey(): ?string
    {
        return $this->key;
    }
}
