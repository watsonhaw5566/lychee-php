<?php

declare(strict_types=1);

namespace Lychee\validation;

use RuntimeException;

class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors
     * @param string|null           $message 自定义提示信息；为 null 时自动拼接 $errors 中的实际校验错误
     * @param int                   $code    HTTP 状态码（默认 400）
     */
    public function __construct(
        public readonly array $errors,
        ?string $message = null,
        int $code = 400,
    ) {
        $message ??= $errors !== []
            ? implode('；', $errors)
            : 'Validation failed.';

        parent::__construct($message, $code);
    }
}
