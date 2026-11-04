<?php

declare(strict_types=1);

namespace Lychee\support;

use ReflectionObject;
use WeakMap;

/**
 * 变量调试输出器。
 *
 * 提供类似 symfony/var-dumper 的调试输出能力，支持 CLI（ANSI 彩色）
 * 与 Web（HTML）两种输出模式，内置循环引用检测与递归深度限制。
 *
 * 配色沿用 lychee-php 异常页的品牌色系，Web 输出通过 CSS 变量
 * 自动适配页面的深色模式（data-theme="dark"）。
 *
 * 典型用法：
 *   $dumper = new Dumper(html: false);
 *   echo $dumper->dump($var);
 *
 * 通常通过全局辅助函数 dump() / dd() 调用，无需手动实例化。
 */
class Dumper
{
    /** 默认最大递归深度，防止深层嵌套结构耗尽内存 */
    public const MAX_DEPTH = 50;

    /** CLI 模式下各语法元素对应的 ANSI 颜色码 */
    private const CLI_COLORS = [
        'null'      => "\033[90m",
        'true'      => "\033[32m",
        'false'     => "\033[31m",
        'number'    => "\033[34m",
        'string'    => "\033[33m",
        'key'       => "\033[36m",
        'class'     => "\033[35m",
        'muted'     => "\033[90m",
        'punct'     => "\033[37m",
        'protected' => "\033[33m",
        'private'   => "\033[31m",
    ];

    private const CLI_RESET = "\033[0m";

    /** 是否已注入过样式标签（Web 模式下仅注入一次） */
    private static bool $styleIncluded = false;

    /**
     * @param bool $html     是否输出 HTML（Web 环境），false 时输出 ANSI 彩色文本（CLI）
     * @param int  $maxDepth 最大递归深度，超过后以省略号截断
     */
    public function __construct(
        private readonly bool $html = false,
        private readonly int $maxDepth = self::MAX_DEPTH,
    ) {
    }

    /**
     * 将变量格式化为调试输出字符串。
     *
     * @param mixed $value 待输出的变量
     */
    public function dump(mixed $value): string
    {
        /** @var WeakMap<object, true> $seen 用于循环引用检测 */
        $seen = new WeakMap();

        $output = $this->format($value, 0, $seen);

        if ($this->html) {
            return $this->wrapHtml($output);
        }

        return rtrim($output) . PHP_EOL;
    }

    /**
     * 重置样式注入标记（用于常驻进程请求间清理）。
     */
    public static function resetStyle(): void
    {
        self::$styleIncluded = false;
    }

    /**
     * 按变量类型分派格式化逻辑。
     *
     * @param WeakMap<object, true> $seen
     */
    private function format(mixed $value, int $depth, WeakMap $seen): string
    {
        if ($depth >= $this->maxDepth) {
            return $this->color('…', 'muted');
        }

        return match (true) {
            $value === null                         => $this->color('null', 'null'),
            is_bool($value)                         => $this->color($value ? 'true' : 'false', $value ? 'true' : 'false'),
            is_int($value), is_float($value)        => $this->color((string) $value, 'number'),
            is_string($value)                       => $this->formatString($value),
            is_array($value)                        => $this->formatArray($value, $depth, $seen),
            is_object($value)                       => $this->formatObject($value, $depth, $seen),
            is_resource($value)                     => $this->color('resource(' . get_resource_type($value) . ')', 'muted'),
            gettype($value) === 'resource (closed)' => $this->color('resource (closed)', 'muted'),
            default                                 => $this->color(get_debug_type($value), 'muted'),
        };
    }

    /**
     * 格式化字符串：带引号与字节长度，Web 模式下转义 HTML 特殊字符。
     */
    private function formatString(string $value): string
    {
        $escaped = $this->html ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;

        return $this->color('"' . $escaped . '"', 'string')
            . $this->color(' (' . strlen($value) . ')', 'muted');
    }

    /**
     * 格式化数组，输出元素数量与键值对。
     *
     * @param WeakMap<object, true> $seen
     */
    private function formatArray(array $value, int $depth, WeakMap $seen): string
    {
        $count = count($value);

        if ($count === 0) {
            return $this->color('[]', 'punct');
        }

        $indent      = str_repeat('  ', $depth + 1);
        $closeIndent = str_repeat('  ', $depth);
        $lines       = [];

        $lines[] = $this->color('array:' . $count . ' [', 'punct');

        foreach ($value as $key => $item) {
            $keyStr  = $this->formatKey($key);
            $valStr  = $this->format($item, $depth + 1, $seen);
            $lines[] = $indent . $keyStr . ' ' . $this->color('=>', 'punct') . ' ' . $valStr;
        }

        $lines[] = $closeIndent . $this->color(']', 'punct');

        return implode("\n", $lines);
    }

    /**
     * 格式化数组键名：整型键不加引号，字符串键加引号。
     */
    private function formatKey(int|string $key): string
    {
        if (is_int($key)) {
            return $this->color((string) $key, 'number');
        }

        $escaped = $this->html ? htmlspecialchars($key, ENT_QUOTES, 'UTF-8') : $key;

        return $this->color('"' . $escaped . '"', 'key');
    }

    /**
     * 格式化对象：优先使用 __debugInfo()，否则通过反射读取全部属性。
     *
     * 已访问过的对象以 *RECURSION* 标记，避免循环引用导致无限递归。
     *
     * @param WeakMap<object, true> $seen
     */
    private function formatObject(object $value, int $depth, WeakMap $seen): string
    {
        if ($seen->offsetExists($value)) {
            return $this->color('*RECURSION*', 'muted');
        }

        $seen[$value] = true;

        $class = $value::class;

        if (method_exists($value, '__debugInfo')) {
            $props = $value->__debugInfo();
        } else {
            $props = $this->getObjectProperties($value);
        }

        $header = $this->color($class, 'class');

        if ($props === []) {
            return $header . ' ' . $this->color('{}', 'punct');
        }

        $indent      = str_repeat('  ', $depth + 1);
        $closeIndent = str_repeat('  ', $depth);
        $lines       = [];

        $lines[] = $header . ' ' . $this->color('{', 'punct');

        foreach ($props as $name => $val) {
            $nameStr = $this->formatPropertyName((string) $name);
            $valStr  = $this->format($val, $depth + 1, $seen);
            $lines[] = $indent . $nameStr . ': ' . $valStr;
        }

        $lines[] = $closeIndent . $this->color('}', 'punct');

        return implode("\n", $lines);
    }

    /**
     * 通过反射读取对象的全部属性（含 private / protected）。
     *
     * 属性名前加上可见性前缀：
     *   + public
     *   # protected
     *   - private
     *
     * @return array<string, mixed>
     */
    private function getObjectProperties(object $obj): array
    {
        $reflect = new ReflectionObject($obj);
        $props   = [];

        foreach ($reflect->getProperties() as $prop) {
            $prop->setAccessible(true);
            $name                   = $prop->getName();
            $prefix                 = $prop->isPrivate() ? '-' : ($prop->isProtected() ? '#' : '+');
            $props[$prefix . $name] = $prop->getValue($obj);
        }

        return $props;
    }

    /**
     * 根据属性名前缀（+/#/-）选择对应颜色。
     */
    private function formatPropertyName(string $name): string
    {
        $prefix = $name[0] ?? '';
        $color  = match ($prefix) {
            '-'     => 'private',
            '#'     => 'protected',
            default => 'key',
        };

        return $this->color($name, $color);
    }

    /**
     * 为文本包裹颜色标记（CLI 为 ANSI 转义码，HTML 为 span 标签）。
     */
    private function color(string $text, string $type): string
    {
        if ($this->html) {
            return '<span class="lychee-dump__' . $type . '">' . $text . '</span>';
        }

        $code = self::CLI_COLORS[$type] ?? '';

        return $code . $text . self::CLI_RESET;
    }

    /**
     * 将格式化后的文本包裹为 HTML 结构。
     *
     * 样式标签仅在首次输出时注入一次，避免多次 dump() 产生重复 CSS。
     */
    private function wrapHtml(string $content): string
    {
        $style = '';
        if (!self::$styleIncluded) {
            $style               = $this->htmlStyle();
            self::$styleIncluded = true;
        }

        return $style . '<pre class="lychee-dump">' . $content . '</pre>';
    }

    /**
     * Web 输出的 CSS 样式，使用 CSS 变量以适配深色模式。
     */
    private function htmlStyle(): string
    {
        return <<<'CSS'
<style>
.lychee-dump {
    background: var(--dump-bg, #FBF1ED);
    color: var(--dump-text, #5A3E3A);
    padding: 16px;
    border-radius: 8px;
    border: 1px solid var(--dump-border, #F5E3E0);
    font-family: "SF Mono", "JetBrains Mono", Consolas, monospace;
    font-size: 13px;
    line-height: 1.6;
    overflow-x: auto;
    margin: 8px 0;
    white-space: pre;
}
[data-theme="dark"] .lychee-dump {
    --dump-bg: #251C1F;
    --dump-text: #D8C5C0;
    --dump-border: #3D2B2E;
}
:root {
    --dump-null: #9A8A8A;
    --dump-true: #7CB342;
    --dump-false: #D6336C;
    --dump-number: #4A90D9;
    --dump-string: #C19A6B;
    --dump-key: #D6336C;
    --dump-class: #7CB342;
    --dump-muted: #9A8A8A;
    --dump-punct: #9A8A8A;
    --dump-protected: #C19A6B;
    --dump-private: #D6336C;
}
[data-theme="dark"] {
    --dump-true: #8BC34A;
    --dump-false: #E8508A;
    --dump-number: #64B5F6;
    --dump-string: #D8B98A;
    --dump-key: #E8508A;
    --dump-class: #8BC34A;
    --dump-protected: #D8B98A;
    --dump-private: #E8508A;
}
.lychee-dump__null      { color: var(--dump-null); }
.lychee-dump__true      { color: var(--dump-true); font-weight: 600; }
.lychee-dump__false     { color: var(--dump-false); font-weight: 600; }
.lychee-dump__number    { color: var(--dump-number); }
.lychee-dump__string    { color: var(--dump-string); }
.lychee-dump__key       { color: var(--dump-key); }
.lychee-dump__class     { color: var(--dump-class); font-weight: 600; }
.lychee-dump__muted     { color: var(--dump-muted); }
.lychee-dump__punct     { color: var(--dump-punct); }
.lychee-dump__protected { color: var(--dump-protected); }
.lychee-dump__private   { color: var(--dump-private); }
</style>
CSS;
    }
}
