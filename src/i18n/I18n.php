<?php

declare(strict_types=1);

namespace Lychee\i18n;

/**
 * 轻量级国际化（i18n）管理器。
 *
 * 基于 PHP 数组文件的翻译加载器，支持：
 * - 按语言分组加载翻译文件（{path}/{locale}/{group}.php）
 * - 点号分隔的嵌套键查找（messages.welcome）
 * - 占位符参数替换（:name）
 * - 回退语言（fallback locale）
 * - 已加载分组的内存缓存
 */
class I18n
{
    /** 当前语言 */
    protected string $locale;

    /** 回退语言 */
    protected string $fallbackLocale;

    /** 翻译文件根目录 */
    protected string $path;

    /**
     * 已加载的翻译分组缓存。
     *
     * @var array<string, array<string, mixed>>  [locale => [group => lines]]
     */
    protected array $loaded = [];

    /**
     * @param array{locale?: string, fallback_locale?: string, path?: string} $config
     */
    public function __construct(array $config = [])
    {
        $this->locale         = (string) ($config['locale'] ?? 'zh-CN');
        $this->fallbackLocale = (string) ($config['fallback_locale'] ?? 'en');
        $this->path           = rtrim((string) ($config['path'] ?? ''), DIRECTORY_SEPARATOR);
    }

    /**
     * 翻译指定键。
     *
     * 键格式为「分组.键名」，例如 `messages.welcome`。
     * 当当前语言缺失时，会回退到 fallback_locale；仍找不到则返回键本身。
     *
     * @param  string               $key     翻译键（支持点号嵌套）
     * @param  array<string, mixed> $replace 占位符替换值（:name => value）
     * @param  string|null          $locale  指定语言，为 null 时使用当前语言
     */
    public function lang(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale ??= $this->locale;

        $line = $this->getLine($key, $locale);

        // 回退语言
        if ($line === null && $locale !== $this->fallbackLocale) {
            $line = $this->getLine($key, $this->fallbackLocale);
        }

        if ($line === null) {
            return $key;
        }

        return $this->makeReplacements($line, $replace);
    }

    /**
     * 判断翻译键是否存在（含回退语言）。
     */
    public function has(string $key, ?string $locale = null): bool
    {
        $locale ??= $this->locale;

        return $this->getLine($key, $locale) !== null
            || ($locale !== $this->fallbackLocale && $this->getLine($key, $this->fallbackLocale) !== null);
    }

    /**
     * 获取当前语言。
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * 设置当前语言。
     */
    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /**
     * 获取回退语言。
     */
    public function getFallbackLocale(): string
    {
        return $this->fallbackLocale;
    }

    /**
     * 获取翻译文件根目录。
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * 解析单个翻译行，支持点号嵌套。
     *
     * @return string|null  找不到时返回 null
     */
    protected function getLine(string $key, string $locale): ?string
    {
        if ($key === '') {
            return null;
        }

        // 拆分分组与嵌套键
        $segments = explode('.', $key);
        $group    = array_shift($segments);

        if ($group === null) {
            return null;
        }

        $lines = $this->load($group, $locale);

        if ($segments === []) {
            // 仅指定分组，若分组本身是字符串则直接返回
            return is_string($lines) ? $lines : null;
        }

        $value = $lines;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return is_string($value) ? $value : null;
    }

    /**
     * 加载指定分组的翻译文件。
     *
     * @return array<string, mixed>
     */
    protected function load(string $group, string $locale): array
    {
        if (isset($this->loaded[$locale][$group])) {
            return $this->loaded[$locale][$group];
        }

        $file = $this->path . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $group . '.php';

        $lines = [];
        if (is_file($file)) {
            $data = include $file;
            if (is_array($data)) {
                $lines = $data;
            }
        }

        $this->loaded[$locale][$group] = $lines;

        return $lines;
    }

    /**
     * 对翻译行做占位符替换。
     *
     * 支持 `:name` 与 `:NAME`（首字母大写）、`:NAME`（全大写）。
     *
     * @param  array<string, mixed> $replace
     */
    protected function makeReplacements(string $line, array $replace): string
    {
        if ($replace === []) {
            return $line;
        }

        // 排序：长键优先，避免短键前缀误替换
        uksort($replace, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($replace as $key => $value) {
            $value = (string) $value;
            $line  = str_replace(
                [':' . $key, ':' . strtoupper($key), ':' . ucfirst($key)],
                [$value, strtoupper($value), ucfirst($value)],
                $line,
            );
        }

        return $line;
    }
}
