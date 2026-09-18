<?php

declare(strict_types=1);

namespace Lychee\auth;

/**
 * Token 读取驱动接口。
 *
 * 负责从当前请求中提取 token，SaToken 通过该接口解耦 token 的传递方式
 * （HTTP Header、Cookie、Query 参数等）。
 */
interface TokenReaderInterface
{
    /**
     * 从当前请求中读取 token。
     */
    public function read(): ?string;
}
