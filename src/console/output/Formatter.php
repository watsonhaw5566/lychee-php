<?php

declare(strict_types=1);

namespace Lychee\console\output;

/**
 * 控制台输出格式化器。
 *
 * 支持 <info>、<error>、<comment>、<question> 等标签。
 */
class Formatter
{
    protected bool $decorated = false;

    /** @var array<string, array{fg: string, bg: string}> */
    protected array $styles = [
        'info'     => ['fg' => 'green', 'bg' => ''],
        'error'    => ['fg' => 'white', 'bg' => 'red'],
        'comment'  => ['fg' => 'yellow', 'bg' => ''],
        'question' => ['fg' => 'black', 'bg' => 'cyan'],
    ];

    public function setDecorated(bool $decorated): void
    {
        $this->decorated = $decorated;
    }

    public function isDecorated(): bool
    {
        return $this->decorated;
    }

    public function hasStyle(string $name): bool
    {
        return isset($this->styles[$name]);
    }

    public function format(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        if (!$this->decorated) {
            return strip_tags($message);
        }

        $tags = implode('|', array_keys($this->styles));

        return preg_replace_callback("#<({$tags})>(.*?)</\\1>#s", function ($matches) {
            $style = $this->styles[$matches[1]];

            return $this->applyStyle($matches[2], $style['fg'], $style['bg']);
        }, $message) ?? $message;
    }

    protected function applyStyle(string $text, string $fg = '', string $bg = ''): string
    {
        $codes = [];

        $fgMap = ['black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33, 'blue' => 34, 'magenta' => 35, 'cyan' => 36, 'white' => 37];
        $bgMap = ['black' => 40, 'red' => 41, 'green' => 42, 'yellow' => 43, 'blue' => 44, 'magenta' => 45, 'cyan' => 46, 'white' => 47];

        if ($fg && isset($fgMap[$fg])) {
            $codes[] = $fgMap[$fg];
        }
        if ($bg && isset($bgMap[$bg])) {
            $codes[] = $bgMap[$bg];
        }

        if ($codes === []) {
            return $text;
        }

        return "\033[" . implode(';', $codes) . 'm' . $text . "\033[0m";
    }
}
