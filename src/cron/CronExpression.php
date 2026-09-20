<?php

declare(strict_types=1);

namespace Lychee\cron;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * Cron 表达式解析器。
 *
 * 支持标准 5 段格式：分 时 日 月 周
 *   * * * * *
 *   | | | | |
 *   | | | | +--- 星期 (0-7, 0 和 7 都是周日)
 *   | | | +----- 月份 (1-12)
 *   | | +------- 日期 (1-31)
 *   | +--------- 小时 (0-23)
 *   +----------- 分钟 (0-59)
 *
 * 支持语法：* 任意值 , 列表 - 范围 / 步长
 * 支持月份和星期的英文缩写（jan-dec / sun-sat）。
 *
 * 日期与星期的匹配遵循标准 cron（Vixie）语义：
 *   - 任一字段为通配（星号 或 星号/n 步长）时，两者按「与」匹配
 *   - 两者都被限制时，按「或」匹配（日期或星期任一命中即可），
 *     例如 '30 4 1,15 * 5' 表示每月 1 号、15 号以及每周五
 */
class CronExpression
{
    /** 各字段的取值范围 [min, max] */
    private const RANGES = [
        0 => [0, 59],   // minute
        1 => [0, 23],   // hour
        2 => [1, 31],   // day of month
        3 => [1, 12],   // month
        4 => [0, 7],    // day of week（7 在解析后归一为 0，均表示周日）
    ];

    /** 月份英文缩写到数字的映射 */
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
        'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
        'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /** 星期英文缩写到数字的映射 */
    private const WEEKDAYS = [
        'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3,
        'thu' => 4, 'fri' => 5, 'sat' => 6,
    ];

    /** @var array<int, list<int>> 各字段允许的取值集合 */
    private array $values = [];

    /** @var array<int, bool> 各字段原始值是否为通配，用于日/星期 OR 判定 */
    private array $wildcards = [];

    public function __construct(private readonly string $expression)
    {
        $this->parse();
    }

    /**
     * 解析 cron 表达式，生成各字段的取值集合。
     */
    private function parse(): void
    {
        $parts = preg_split('/\s+/', trim($this->expression));

        if ($parts === false || count($parts) !== 5) {
            throw new InvalidArgumentException(
                "Invalid cron expression '{$this->expression}': expected 5 fields"
            );
        }

        foreach ($parts as $index => $part) {
            $this->wildcards[$index] = $this->isWildcard($part);
            $this->values[$index]    = $this->parseField($part, $index);
        }
    }

    /**
     * 判断字段是否为通配形式。
     *
     * 仅当所有逗号分隔段均为通配符（或带步长的通配符）时视为通配，
     * 与 Vixie cron 的 STAR 标志一致；显式枚举如 '0-6' 即使覆盖
     * 完整范围也不算通配。
     */
    private function isWildcard(string $field): bool
    {
        foreach (explode(',', $field) as $segment) {
            if (trim(explode('/', $segment)[0]) !== '*') {
                return false;
            }
        }

        return true;
    }

    /**
     * 解析单个字段，返回允许的取值列表。
     *
     * @return list<int>
     */
    private function parseField(string $field, int $index): array
    {
        [$min, $max] = self::RANGES[$index];

        // 月份和星期支持英文缩写
        $field = $this->normalizeLiterals($field, $index);

        $values = [];

        // 处理逗号分隔的列表
        foreach (explode(',', $field) as $segment) {
            $step  = 1;
            $range = $segment;

            // 处理步长 */5 或 1-10/2
            if (str_contains($segment, '/')) {
                [$range, $stepStr] = explode('/', $segment, 2);
                $step              = (int) $stepStr;
                if ($step < 1) {
                    throw new InvalidArgumentException("Invalid step value '{$stepStr}' in cron field '{$field}'");
                }
            }

            if ($range === '*') {
                $rangeMin = $min;
                $rangeMax = $max;
            } elseif (str_contains($range, '-')) {
                [$rangeMin, $rangeMax] = explode('-', $range, 2);
                $rangeMin              = (int) $rangeMin;
                $rangeMax              = (int) $rangeMax;
            } else {
                $rangeMin = (int) $range;
                $rangeMax = str_contains($segment, '/') ? $max : $rangeMin;
            }

            if ($rangeMin < $min || $rangeMax > $max || $rangeMin > $rangeMax) {
                throw new InvalidArgumentException(
                    "Value out of range [{$min}, {$max}] in cron field '{$field}'"
                );
            }

            for ($i = $rangeMin; $i <= $rangeMax; $i += $step) {
                $values[] = $i;
            }
        }

        // 星期 7 与 0 均表示周日，统一归一为 0，保证 '1-7'、'5,7' 等写法正确展开
        if ($index === 4) {
            $values = array_map(static fn (int $v): int => $v === 7 ? 0 : $v, $values);
        }

        return array_values(array_unique($values));
    }

    /**
     * 将月份/星期的英文缩写转换为数字。
     */
    private function normalizeLiterals(string $field, int $index): string
    {
        if ($index === 3) {
            return str_ireplace(array_keys(self::MONTHS), array_values(self::MONTHS), $field);
        }

        if ($index === 4) {
            return str_ireplace(array_keys(self::WEEKDAYS), array_values(self::WEEKDAYS), $field);
        }

        return $field;
    }

    /**
     * 判断给定时间是否匹配该 cron 表达式。
     */
    public function isDue(DateTimeInterface $time): bool
    {
        if (!in_array((int) $time->format('i'), $this->values[0], true)
            || !in_array((int) $time->format('G'), $this->values[1], true)
            || !in_array((int) $time->format('n'), $this->values[3], true)
        ) {
            return false;
        }

        $dayOfMonth = in_array((int) $time->format('j'), $this->values[2], true);
        $dayOfWeek  = in_array((int) $time->format('w'), $this->values[4], true);

        // 日期与星期均被限制时按「或」匹配；任一为通配时按「与」匹配
        $dayMatches = ($this->wildcards[2] || $this->wildcards[4])
            ? $dayOfMonth && $dayOfWeek
            : $dayOfMonth || $dayOfWeek;

        return $dayMatches;
    }

    /**
     * 获取原始 cron 表达式。
     */
    public function getExpression(): string
    {
        return $this->expression;
    }
}
