<?php

declare(strict_types=1);

namespace Lychee\validate;

/**
 * 校验规则的默认错误提示。
 *
 * 消息中的占位符：
 *   {:field}  字段名
 *   {:rule}   规则名
 *   {:0}      第一个参数
 *   {:1}      第二个参数
 */
class Lang
{
    /**
     * 中文默认提示。
     *
     * @var array<string, string>
     */
    public static array $zh = [
        'require'    => '{:field}不能为空',
        'accepted'   => '{:field}必须接受',
        'declined'   => '{:field}必须拒绝',
        'number'     => '{:field}必须是数字',
        'integer'    => '{:field}必须是整数',
        'float'      => '{:field}必须是浮点数',
        'boolean'    => '{:field}必须是布尔值',
        'array'      => '{:field}必须是数组',
        'date'       => '{:field}必须是日期',
        'alpha'      => '{:field}只能是字母',
        'alphaNum'   => '{:field}只能是字母和数字',
        'email'      => '{:field}格式不正确',
        'url'        => '{:field}必须是 URL',
        'ip'         => '{:field}必须是 IP 地址',
        'mobile'     => '{:field}必须是手机号',
        'regex'      => '{:field}格式不正确',
        'gt'         => '{:field}必须大于 {:0}',
        'lt'         => '{:field}必须小于 {:0}',
        'egt'        => '{:field}必须大于等于 {:0}',
        'elt'        => '{:field}必须小于等于 {:0}',
        'eq'         => '{:field}必须等于 {:0}',
        'same'       => '{:field}必须和 {:0} 相同',
        'confirm'    => '{:field}和确认字段不一致',
        'different'  => '{:field}不能和 {:0} 相同',
        'in'         => '{:field}必须在 {:0} 范围内',
        'notIn'      => '{:field}不能在 {:0} 范围内',
        'between'    => '{:field}只能在 {:0} - {:1} 之间',
        'notBetween' => '{:field}不能在 {:0} - {:1} 之间',
        'length'     => '{:field}长度不符合要求',
        'max'        => '{:field}长度不能超过 {:0}',
        'min'        => '{:field}长度不能少于 {:0}',
        'file'       => '{:field}必须是文件',
        'image'      => '{:field}必须是图片',
        'fileExt'    => '{:field}扩展名无效',
        'fileMime'   => '{:field}MIME 类型无效',
        'fileSize'   => '{:field}文件大小超限',
    ];

    /**
     * 英文默认提示。
     *
     * @var array<string, string>
     */
    public static array $en = [
        'require'    => '{:field} is required',
        'accepted'   => '{:field} must be accepted',
        'declined'   => '{:field} must be declined',
        'number'     => '{:field} must be a number',
        'integer'    => '{:field} must be an integer',
        'float'      => '{:field} must be a float',
        'boolean'    => '{:field} must be a boolean',
        'array'      => '{:field} must be an array',
        'date'       => '{:field} must be a date',
        'alpha'      => '{:field} must only contain letters',
        'alphaNum'   => '{:field} must only contain letters and numbers',
        'email'      => '{:field} is not a valid email',
        'url'        => '{:field} is not a valid URL',
        'ip'         => '{:field} is not a valid IP address',
        'mobile'     => '{:field} is not a valid mobile number',
        'regex'      => '{:field} format is invalid',
        'gt'         => '{:field} must be greater than {:0}',
        'lt'         => '{:field} must be less than {:0}',
        'egt'        => '{:field} must be greater than or equal to {:0}',
        'elt'        => '{:field} must be less than or equal to {:0}',
        'eq'         => '{:field} must equal {:0}',
        'same'       => '{:field} must be the same as {:0}',
        'confirm'    => '{:field} does not match confirmation',
        'different'  => '{:field} must be different from {:0}',
        'in'         => '{:field} must be one of {:0}',
        'notIn'      => '{:field} must not be one of {:0}',
        'between'    => '{:field} must be between {:0} and {:1}',
        'notBetween' => '{:field} must not be between {:0} and {:1}',
        'length'     => '{:field} length is invalid',
        'max'        => '{:field} may not be longer than {:0}',
        'min'        => '{:field} must be at least {:0} characters',
        'file'       => '{:field} must be a file',
        'image'      => '{:field} must be an image',
        'fileExt'    => '{:field} has an invalid extension',
        'fileMime'   => '{:field} has an invalid MIME type',
        'fileSize'   => '{:field} exceeds the max file size',
    ];

    /**
     * 查询规则的默认消息。
     */
    public static function get(string $rule, string $lang = 'zh'): string
    {
        $table = $lang === 'en' ? self::$en : self::$zh;

        return $table[$rule] ?? ($rule . ' validation failed');
    }
}
