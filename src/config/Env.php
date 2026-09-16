<?php

declare(strict_types=1);

namespace Lychee\config;

/**
 * 环境变量（.env）加载器。
 *
 * 解析项目根目录下的 .env 文件，将 KEY=VALUE 形式的变量写入
 * $_ENV、$_SERVER 以及 putenv()，供 env() 辅助函数读取。
 *
 * 支持：
 *   - 以 # 开头的注释行
 *   - KEY=VALUE 形式的赋值
 *   - 单引号 / 双引号包裹的值（会自动去除引号）
 *   - 空行自动跳过
 */
class Env
{
    /**
     * 加载指定路径的 .env 文件。
     *
     * 文件不存在时静默跳过，不抛出异常。
     */
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // 跳过注释行与空行
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // 仅处理包含等号的赋值行
            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);

            $name  = trim($name);
            $value = trim($value);

            if ($name === '') {
                continue;
            }

            // 去除首尾引号
            $value = self::stripQuotes($value);

            // 同步写入三处，保证 getenv() / $_ENV / $_SERVER 均可读取
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }
    }

    /**
     * 去除字符串首尾的成对引号。
     */
    private static function stripQuotes(string $value): string
    {
        $len = strlen($value);

        if ($len < 2) {
            return $value;
        }

        $first = $value[0];
        $last  = $value[$len - 1];

        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
