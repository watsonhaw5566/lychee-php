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
}
