<?php

declare(strict_types=1);

namespace Tests\unit;

use DateTimeImmutable;
use InvalidArgumentException;
use Lychee\cron\CronExpression;
use PHPUnit\Framework\TestCase;

class CronExpressionTest extends TestCase
{
    public function test_every_minute_expression(): void
    {
        $expr = new CronExpression('* * * * *');
        $time = new DateTimeImmutable('2026-09-16 10:30:00');

        $this->assertTrue($expr->isDue($time));
    }

    public function test_specific_minute(): void
    {
        $expr = new CronExpression('30 * * * *');

        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-16 10:30:00')));
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-09-16 10:31:00')));
    }

    public function test_specific_hour_and_minute(): void
    {
        $expr = new CronExpression('0 12 * * *');

        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-16 12:00:00')));
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-09-16 12:01:00')));
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-09-16 11:00:00')));
    }

    public function test_day_of_month(): void
    {
        $expr = new CronExpression('0 0 15 * *');

        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-15 00:00:00')));
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-09-16 00:00:00')));
    }

    public function test_month(): void
    {
        $expr = new CronExpression('0 0 1 1 *');

        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-01-01 00:00:00')));
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-02-01 00:00:00')));
    }

    public function test_day_of_week(): void
    {
        $expr = new CronExpression('0 9 * * 1'); // Monday 9am

        // 2026-09-14 is a Monday
        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-14 09:00:00')));
        // 2026-09-15 is a Tuesday
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-09-15 09:00:00')));
    }

    public function test_step_values(): void
    {
        $expr = new CronExpression('*/5 * * * *');

        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-16 10:00:00')));
        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-16 10:05:00')));
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-09-16 10:01:00')));
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-09-16 10:07:00')));
    }

    public function test_range_values(): void
    {
        $expr = new CronExpression('0 9-17 * * *');

        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-16 09:00:00')));
        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-16 12:00:00')));
        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-16 17:00:00')));
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-09-16 08:00:00')));
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-09-16 18:00:00')));
    }

    public function test_list_values(): void
    {
        $expr = new CronExpression('0 0,12,18 * * *');

        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-16 00:00:00')));
        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-16 12:00:00')));
        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-16 18:00:00')));
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-09-16 06:00:00')));
    }

    public function test_month_name_alias(): void
    {
        $expr = new CronExpression('0 0 1 jan *');

        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-01-01 00:00:00')));
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-02-01 00:00:00')));
    }

    public function test_weekday_name_alias(): void
    {
        $expr = new CronExpression('0 0 * * sun');

        // 2026-09-13 is a Sunday
        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-13 00:00:00')));
        // 2026-09-14 is a Monday
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-09-14 00:00:00')));
    }

    public function test_invalid_expression_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CronExpression('* * * *');
    }

    public function test_invalid_value_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CronExpression('60 * * * *');
    }

    public function test_get_expression(): void
    {
        $expr = new CronExpression('*/5 9-17 * * 1-5');

        $this->assertSame('*/5 9-17 * * 1-5', $expr->getExpression());
    }

    public function test_weekday_seven_is_treated_as_sunday(): void
    {
        $expr = new CronExpression('0 0 * * 7');

        // 2026-09-13 is a Sunday
        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-13 00:00:00')));
        // 2026-09-14 is a Monday
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-09-14 00:00:00')));
    }

    public function test_weekday_range_one_to_seven_covers_all_days(): void
    {
        $expr = new CronExpression('0 0 * * 1-7');

        // 1-7 展开后归一为 1,2,3,4,5,6,0，覆盖周一到周日
        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-13 00:00:00'))); // Sunday
        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-14 00:00:00'))); // Monday
        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-19 00:00:00'))); // Saturday
    }

    public function test_weekday_list_with_seven(): void
    {
        $expr = new CronExpression('0 0 * * 5,7'); // Friday and Sunday

        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-13 00:00:00')));  // Sunday
        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-09-18 00:00:00')));  // Friday
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-09-14 00:00:00'))); // Monday
    }

    public function test_day_and_weekday_uses_or_when_both_restricted(): void
    {
        // 每月 1 号、15 号 以及 每周五 00:00 触发（Vixie OR 语义）
        $expr = new CronExpression('0 0 1,15 * 5');

        // 2026-01-01 is a Thursday, day=1 matches → OR true
        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-01-01 00:00:00')));
        // 2026-01-15 is a Thursday, day=15 matches → OR true
        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-01-15 00:00:00')));
        // 2026-01-02 is a Friday, weekday=5 matches → OR true
        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-01-02 00:00:00')));
        // 2026-01-05 is a Monday, neither matches → false
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-01-05 00:00:00')));
    }

    public function test_day_and_weekday_uses_and_when_one_is_wildcard(): void
    {
        // 仅日期被限制，星期为通配 → 按「与」匹配（日期必须命中）
        $expr = new CronExpression('0 0 15 * *');

        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-01-15 00:00:00')));
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-01-16 00:00:00')));
    }

    public function test_explicit_full_range_weekday_is_not_wildcard(): void
    {
        // '0-6' 虽然覆盖全部星期，但属于显式枚举，应与日期形成 OR 匹配
        // 因此 1 号（无论星期几）都应命中
        $expr = new CronExpression('0 0 1 * 0-6');

        // 2026-01-01 Thursday: day=1 matches → OR true
        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-01-01 00:00:00')));
        // 2026-01-02 Friday: day≠1 but weekday=5 ∈ {0..6} → OR true
        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-01-02 00:00:00')));
    }

    public function test_stepped_wildcard_counts_as_wildcard(): void
    {
        // 星期为 */2（带步长的通配符），仍视为通配 → 日期与星期按「与」匹配
        $expr = new CronExpression('0 0 15 * */2');

        $this->assertTrue($expr->isDue(new DateTimeImmutable('2026-01-15 00:00:00')));
        $this->assertFalse($expr->isDue(new DateTimeImmutable('2026-01-16 00:00:00')));
    }
}
