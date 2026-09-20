<?php

declare(strict_types=1);

namespace Lychee\exception;

use Lychee\http\HttpException as BaseHttpException;

/**
 * 业务层 HTTP 异常。
 *
 * 用法：
 *   throw new HttpException(400, '用户不存在');
 *   throw new HttpException(404, '资源不存在');
 *
 * 继承自框架内部的 {@see \Lychee\http\HttpException}，因此会被
 * 全局异常处理器按 statusCode 自动渲染为对应 HTTP 状态码的响应，
 * 且不会写入错误日志。
 */
class HttpException extends BaseHttpException
{
}
