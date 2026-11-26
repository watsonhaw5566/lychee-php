<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\migration\schema\ColumnTypeParser;
use PHPUnit\Framework\TestCase;

class ColumnTypeParserTest extends TestCase
{
    public function test_varchar_maps_to_string_with_length(): void
    {
        $parsed = ColumnTypeParser::parse('varchar(255)');

        $this->assertSame('string', $parsed['type']);
        $this->assertSame(255, $parsed['length']);
        $this->assertFalse($parsed['unsigned']);
    }

    public function test_bigint_unsigned_maps_to_biginteger_unsigned(): void
    {
        $parsed = ColumnTypeParser::parse('bigint(20) unsigned');

        $this->assertSame('biginteger', $parsed['type']);
        $this->assertTrue($parsed['unsigned']);
        $this->assertSame(20, $parsed['length']);
    }

    public function test_tinyint_one_maps_to_boolean(): void
    {
        $this->assertSame('boolean', ColumnTypeParser::parse('tinyint(1)')['type']);
    }

    public function test_tinyint_other_maps_to_tinyinteger(): void
    {
        $parsed = ColumnTypeParser::parse('tinyint(4)');
        $this->assertSame('tinyinteger', $parsed['type']);
        $this->assertSame(4, $parsed['length']);
    }

    public function test_decimal_maps_precision_and_scale(): void
    {
        $parsed = ColumnTypeParser::parse('decimal(10,2)');

        $this->assertSame('decimal', $parsed['type']);
        $this->assertSame(10, $parsed['precision']);
        $this->assertSame(2, $parsed['scale']);
    }

    public function test_enum_parses_values(): void
    {
        $parsed = ColumnTypeParser::parse("enum('active','in''active')");

        $this->assertSame('enum', $parsed['type']);
        $this->assertSame(['active', "in'active"], $parsed['values']);
    }

    public function test_datetime_with_fractional_seconds(): void
    {
        $parsed = ColumnTypeParser::parse('datetime(6)');

        $this->assertSame('datetime', $parsed['type']);
        $this->assertSame(6, $parsed['length']);
    }

    public function test_unknown_type_passes_through(): void
    {
        $this->assertSame('year', ColumnTypeParser::parse('year')['type']);
        $this->assertSame('mediumtext', ColumnTypeParser::parse('mediumtext')['type']);
    }

    public function test_int_without_length(): void
    {
        $parsed = ColumnTypeParser::parse('int');

        $this->assertSame('integer', $parsed['type']);
        $this->assertNull($parsed['length']);
    }
}
