<?php

declare(strict_types=1);

namespace Lychee\validate;

use Lychee\http\File;

/**
 * 内置校验规则集合。
 *
 * 每个方法接受 (值, 参数数组, 完整数据, 字段名) 并返回：
 *   - true  校验通过
 *   - false 校验失败（使用默认消息）
 *   - string 校验失败且使用返回值作为自定义消息
 *
 * 规则由 Validate 调度，用户不直接调用这里的方法。
 */
class Rule
{
    public static function require(mixed $value, array $params, array $data, string $field): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        if (is_array($value) && $value === []) {
            return false;
        }

        return true;
    }

    public static function accepted(mixed $value, array $params, array $data, string $field): bool
    {
        return in_array($value, [1, '1', true, 'true', 'yes', 'on'], true);
    }

    public static function declined(mixed $value, array $params, array $data, string $field): bool
    {
        return in_array($value, [0, '0', false, 'false', 'no', 'off'], true);
    }

    public static function number(mixed $value, array $params, array $data, string $field): bool
    {
        return is_numeric($value);
    }

    public static function integer(mixed $value, array $params, array $data, string $field): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    public static function float(mixed $value, array $params, array $data, string $field): bool
    {
        return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
    }

    public static function boolean(mixed $value, array $params, array $data, string $field): bool
    {
        return in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true);
    }

    public static function array(mixed $value, array $params, array $data, string $field): bool
    {
        return is_array($value);
    }

    public static function date(mixed $value, array $params, array $data, string $field): bool
    {
        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        $time = strtotime((string) $value);

        return $time !== false;
    }

    public static function alpha(mixed $value, array $params, array $data, string $field): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z]+$/', $value) === 1;
    }

    public static function alphaNum(mixed $value, array $params, array $data, string $field): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9]+$/', $value) === 1;
    }

    public static function email(mixed $value, array $params, array $data, string $field): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function url(mixed $value, array $params, array $data, string $field): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    public static function ip(mixed $value, array $params, array $data, string $field): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    public static function mobile(mixed $value, array $params, array $data, string $field): bool
    {
        return is_string($value) && preg_match('/^1[3-9]\d{9}$/', $value) === 1;
    }

    public static function regex(mixed $value, array $params, array $data, string $field): bool
    {
        if (!is_string($value) || !isset($params[0])) {
            return false;
        }

        $pattern = (string) $params[0];
        // 允许未加分隔符的正则：'^\d{4}$' 自动包裹成 '/^\d{4}$/'
        if ($pattern === '' || $pattern[0] !== '/') {
            $pattern = '/' . str_replace('/', '\\/', $pattern) . '/';
        }

        return preg_match($pattern, $value) === 1;
    }

    public static function gt(mixed $value, array $params, array $data, string $field): bool
    {
        return isset($params[0]) && is_numeric($value) && $value > $params[0];
    }

    public static function lt(mixed $value, array $params, array $data, string $field): bool
    {
        return isset($params[0]) && is_numeric($value) && $value < $params[0];
    }

    public static function egt(mixed $value, array $params, array $data, string $field): bool
    {
        return isset($params[0]) && is_numeric($value) && $value >= $params[0];
    }

    public static function elt(mixed $value, array $params, array $data, string $field): bool
    {
        return isset($params[0]) && is_numeric($value) && $value <= $params[0];
    }

    public static function eq(mixed $value, array $params, array $data, string $field): bool
    {
        return isset($params[0]) && $value == $params[0];
    }

    public static function same(mixed $value, array $params, array $data, string $field): bool
    {
        return isset($params[0]) && $value === $params[0];
    }

    public static function confirm(mixed $value, array $params, array $data, string $field): bool
    {
        // 默认和 "{field}_confirm" 比较；指定参数则使用参数字段
        $other = $params[0] ?? ($field . '_confirm');

        return array_key_exists($other, $data) && $value === $data[$other];
    }

    public static function different(mixed $value, array $params, array $data, string $field): bool
    {
        if (!isset($params[0])) {
            return false;
        }

        $other = (string) $params[0];

        return array_key_exists($other, $data) && $value !== $data[$other];
    }

    public static function in(mixed $value, array $params, array $data, string $field): bool
    {
        return in_array($value, $params, false);
    }

    public static function notIn(mixed $value, array $params, array $data, string $field): bool
    {
        return !in_array($value, $params, false);
    }

    public static function between(mixed $value, array $params, array $data, string $field): bool
    {
        if (!isset($params[0], $params[1]) || !is_numeric($value)) {
            return false;
        }

        return $value >= $params[0] && $value <= $params[1];
    }

    public static function notBetween(mixed $value, array $params, array $data, string $field): bool
    {
        if (!isset($params[0], $params[1]) || !is_numeric($value)) {
            return false;
        }

        return $value < $params[0] || $value > $params[1];
    }

    public static function length(mixed $value, array $params, array $data, string $field): bool
    {
        if (!isset($params[0])) {
            return false;
        }

        $length = self::valueLength($value);

        if ($length === null) {
            return false;
        }

        // length:5 固定长度；length:2,10 区间长度
        if (isset($params[1])) {
            return $length >= (int) $params[0] && $length <= (int) $params[1];
        }

        return $length === (int) $params[0];
    }

    public static function max(mixed $value, array $params, array $data, string $field): bool
    {
        if (!isset($params[0])) {
            return false;
        }

        $length = self::valueLength($value);

        return $length !== null && $length <= (int) $params[0];
    }

    public static function min(mixed $value, array $params, array $data, string $field): bool
    {
        if (!isset($params[0])) {
            return false;
        }

        $length = self::valueLength($value);

        return $length !== null && $length >= (int) $params[0];
    }

    public static function file(mixed $value, array $params, array $data, string $field): bool
    {
        return $value instanceof File;
    }

    public static function image(mixed $value, array $params, array $data, string $field): bool
    {
        if (!$value instanceof File) {
            return false;
        }

        $mime = $value->getMime();

        return str_starts_with($mime, 'image/');
    }

    public static function fileExt(mixed $value, array $params, array $data, string $field): bool
    {
        if (!$value instanceof File || $params === []) {
            return false;
        }

        $allowed = array_map('strtolower', $params);

        return in_array(strtolower($value->extension()), $allowed, true);
    }

    public static function fileMime(mixed $value, array $params, array $data, string $field): bool
    {
        if (!$value instanceof File || $params === []) {
            return false;
        }

        return in_array($value->getMime(), $params, true);
    }

    public static function fileSize(mixed $value, array $params, array $data, string $field): bool
    {
        if (!$value instanceof File || !isset($params[0])) {
            return false;
        }

        return $value->getSize() <= (int) $params[0];
    }

    /**
     * 计算 length / max / min 规则使用的长度：
     *   - 字符串：按多字节字符数
     *   - 数组：元素个数
     *   - 其他：null（无法计算）
     */
    private static function valueLength(mixed $value): ?int
    {
        if (is_string($value)) {
            return mb_strlen($value);
        }

        if (is_array($value)) {
            return count($value);
        }

        return null;
    }
}
