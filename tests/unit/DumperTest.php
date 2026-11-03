<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\support\Dumper;
use PHPUnit\Framework\TestCase;
use stdClass;
use ReflectionProperty;

class DumperTest extends TestCase
{
    private Dumper $cli;
    private Dumper $html;

    protected function setUp(): void
    {
        $this->cli  = new Dumper(html: false);
        $this->html = new Dumper(html: true);
    }

    public function test_null_is_formatted(): void
    {
        $this->assertStringContainsString('null', $this->cli->dump(null));
    }

    public function test_booleans_are_formatted(): void
    {
        $this->assertStringContainsString('true', $this->cli->dump(true));
        $this->assertStringContainsString('false', $this->cli->dump(false));
    }

    public function test_numbers_are_formatted(): void
    {
        $this->assertStringContainsString('42', $this->cli->dump(42));
        $this->assertStringContainsString('3.14', $this->cli->dump(3.14));
    }

    public function test_string_shows_value_and_length(): void
    {
        $output = $this->cli->dump('hello');
        $this->assertStringContainsString('"hello"', $output);
        $this->assertStringContainsString('(5)', $output);
    }

    public function test_empty_array_is_formatted(): void
    {
        $this->assertStringContainsString('[]', $this->cli->dump([]));
    }

    public function test_array_shows_count_and_items(): void
    {
        $output = $this->cli->dump(['a' => 1, 'b' => 2]);

        $this->assertStringContainsString('array:2 [', $output);
        $this->assertStringContainsString('"a"', $output);
        $this->assertStringContainsString('=>', $output);
        $this->assertStringContainsString('1', $output);
    }

    public function test_object_shows_class_and_properties(): void
    {
        $obj          = new stdClass();
        $obj->name    = 'lychee';
        $obj->version = 1.0;

        $output = $this->cli->dump($obj);

        $this->assertStringContainsString('stdClass', $output);
        $this->assertStringContainsString('name', $output);
        $this->assertStringContainsString('"lychee"', $output);
    }

    public function test_object_with_visibility_prefixes(): void
    {
        $obj = new class () {
            public string $pub     = 'public';
            protected string $prot = 'protected';
            private string $priv   = 'private';
        };

        $output = $this->cli->dump($obj);

        $this->assertStringContainsString('+pub', $output);
        $this->assertStringContainsString('#prot', $output);
        $this->assertStringContainsString('-priv', $output);
    }

    public function test_circular_object_reference_is_detected(): void
    {
        $a         = new stdClass();
        $b         = new stdClass();
        $a->child  = $b;
        $b->parent = $a;

        $output = $this->cli->dump($a);

        $this->assertStringContainsString('*RECURSION*', $output);
    }

    public function test_depth_limit_truncates_deeply_nested_array(): void
    {
        $dumper = new Dumper(html: false, maxDepth: 3);
        $nested = ['level' => ['level' => ['level' => ['level' => 'deep']]]];

        $output = $dumper->dump($nested);

        $this->assertStringContainsString('…', $output);
    }

    public function test_cli_output_contains_ansi_escape_codes(): void
    {
        $output = $this->cli->dump('test');

        $this->assertStringContainsString("\033[", $output);
    }

    public function test_html_output_is_wrapped_in_pre_tag(): void
    {
        $output = $this->html->dump('test');

        $this->assertStringContainsString('<pre class="lychee-dump">', $output);
        $this->assertStringContainsString('</pre>', $output);
    }

    public function test_html_output_escapes_special_characters(): void
    {
        $output = $this->html->dump('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    public function test_html_output_includes_style_only_once(): void
    {
        // 重置静态标记，确保测试可重复运行
        $ref = new ReflectionProperty(Dumper::class, 'styleIncluded');
        $ref->setValue(null, false);

        $first  = $this->html->dump('a');
        $second = $this->html->dump('b');

        $this->assertStringContainsString('<style>', $first);
        // 第二次输出不应再包含 style 标签
        $this->assertStringNotContainsString('<style>', $second);
    }

    public function test_object_debug_info_is_used_when_available(): void
    {
        $obj = new class () {
            public string $secret = 'hidden';

            /** @return array<string, mixed> */
            public function __debugInfo(): array
            {
                return ['safe' => 'visible'];
            }
        };

        $output = $this->cli->dump($obj);

        $this->assertStringContainsString('safe', $output);
        $this->assertStringNotContainsString('secret', $output);
    }

    public function test_resource_is_formatted(): void
    {
        $resource = fopen('php://memory', 'r');
        $output   = $this->cli->dump($resource);

        $this->assertStringContainsString('resource(', $output);
        fclose($resource);
    }
}
