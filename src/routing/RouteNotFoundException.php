<?php

declare(strict_types=1);

namespace Lychee\routing;

use Lychee\http\HttpException;

class RouteNotFoundException extends HttpException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, $code, $previous);
    }
}
