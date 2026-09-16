<?php

declare(strict_types=1);

namespace Lychee\http;

/**
 * JSON 响应。
 */
class JsonResponse extends Response
{
    public function __construct(
        mixed $data,
        int $status = 200,
        array $headers = [],
    ) {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json; charset=utf-8';

        parent::__construct($json, $status, $headers);
    }
}
