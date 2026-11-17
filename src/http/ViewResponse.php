<?php

declare(strict_types=1);

namespace Lychee\http;

/**
 * HTML 视图响应。
 *
 * 由 `view()` 辅助函数返回，Content-Type 固定为 `text/html; charset=utf-8`。
 * 控制器可声明 `: ViewResponse` 返回类型以获得精确的静态分析与 IDE 提示。
 */
class ViewResponse extends Response
{
    public function __construct(
        string $content = '',
        int $status = 200,
        array $headers = [],
    ) {
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'text/html; charset=utf-8';

        parent::__construct($content, $status, $headers);
    }
}
