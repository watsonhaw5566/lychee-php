<?php

declare(strict_types=1);

namespace Lychee\session;

/**
 * Session 存储驱动接口。
 */
interface SessionDriverInterface
{
    /**
     * 读取会话数据（序列化字符串）。
     */
    public function read(string $id): string;

    /**
     * 写入会话数据（序列化字符串）。
     */
    public function write(string $id, string $data): bool;

    /**
     * 销毁会话数据。
     */
    public function destroy(string $id): bool;
}
