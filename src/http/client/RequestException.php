<?php

declare(strict_types=1);

namespace Lychee\http\client;

use RuntimeException;

/**
 * HTTP 请求异常。
 *
 * 当远端返回 4xx/5xx 且调用方显式调用 throw() 时抛出。
 */
class RequestException extends RuntimeException
{
    public function __construct(
        protected ClientResponse $response,
    ) {
        parent::__construct(
            sprintf('HTTP request returned status code %d', $response->status()),
            $response->status(),
        );
    }

    public function getResponse(): ClientResponse
    {
        return $this->response;
    }
}
