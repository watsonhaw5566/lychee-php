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
 *   | | | | +--- 星期 (0-6, 0=周日)
 *   | | | +----- 月份 (1-12)
 *   | | +------- 日期 (1-31)
 *   | +--------- 小时 (0-23)
 *   +----------- 分钟 (0-59)
 *
 * 支持语法：* 任意值 , 列表 - 范围 / 步长
 * 支持月份和星期的英文缩写（jan-dec / sun-sat）。
 */
class CronExpression
{
    /** 各字段的取值范围 [min, max] */
    private const RANGES = [
        0 => [0, 59],   // minute
        1 => [0, 23],   // hour
        2 => [1, 31],   // day of month
        3 => [1, 12],   // month
        4 => [0, 6],    // day of week
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
            $this->values[$index] = $this->parseField($part, $index);
        }
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
        return in_array((int) $time->format('i'), $this->values[0], true)
            && in_array((int) $time->format('G'), $this->values[1], true)
            && in_array((int) $time->format('j'), $this->values[2], true)
            && in_array((int) $time->format('n'), $this->values[3], true)
            && in_array((int) $time->format('w'), $this->values[4], true);
    }

    /**
     * 获取原始 cron 表达式。
     */
    public function getExpression(): string
    {
        return $this->expression;
    }
}
