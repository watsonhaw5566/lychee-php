<?php

declare(strict_types=1);

namespace Lychee\auth;

/**
 * 链式 Token 读取器。
 *
 * 依次尝试多个 reader，返回第一个非空的 token。
 * 默认顺序：Header → Cookie，兼顾 SPA 的 AJAX 请求和传统页面跳转。
 */
class ChainTokenReader implements TokenReaderInterface
{
    /** @var TokenReaderInterface[] */
    protected array $readers = [];

    public function __construct(TokenReaderInterface ...$readers)
    {
        $this->readers = $readers;
    }

    public function read(): ?string
    {
        foreach ($this->readers as $reader) {
            $token = $reader->read();
            if ($token !== null) {
                return $token;
            }
        }

        return null;
    }
}
