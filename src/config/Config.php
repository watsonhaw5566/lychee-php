<?php

declare(strict_types=1);

namespace Lychee\config;

/**
 * 配置管理器。
 *
 * 仅加载 config 目录下的 PHP 配置文件，支持点号分隔的多级读取。
 */
class Config
{
    /** @var array<string, mixed> */
    protected array $config = [];

    public function __construct(
        protected string $path = '',
    ) {
    }

    /**
     * 加载配置文件。
     *
     * @param  string $file 配置文件路径
     * @param  string $name 一级配置名（空则使用文件名）
     * @return array<string, mixed>
     */
    public function load(string $file, string $name = ''): array
    {
        if (is_file($file)) {
            $filename = $file;
        } elseif (is_file($this->path . $file . '.php')) {
            $filename = $this->path . $file . '.php';
        }

        if (isset($filename)) {
            return $this->parse($filename, $name);
        }

        return $this->config;
    }

    /**
     * 解析 PHP 配置文件。
     */
    protected function parse(string $file, string $name): array
    {
        $config = include $file;

        return is_array($config) ? $this->set($config, strtolower($name)) : [];
    }

    /**
     * 检测配置是否存在。
     */
    public function has(string $name): bool
    {
        if (!str_contains($name, '.') && !isset($this->config[strtolower($name)])) {
            return false;
        }

        return !is_null($this->get($name));
    }

    /**
     * 获取配置参数。
     *
     * @param  string|null $name    配置参数名（支持多级 . 分割），为 null 时返回全部
     * @param  mixed       $default 默认值
     */
    public function get(?string $name = null, mixed $default = null): mixed
    {
        if (empty($name)) {
            return $this->config;
        }

        if (!str_contains($name, '.')) {
            return $this->config[strtolower($name)] ?? $default;
        }

        $item    = explode('.', $name);
        $item[0] = strtolower($item[0]);
        $config  = $this->config;

        foreach ($item as $val) {
            if (is_array($config) && isset($config[$val])) {
                $config = $config[$val];
            } else {
                return $default;
            }
        }

        return $config;
    }

    /**
     * 设置配置参数。
     *
     * @param  array<string, mixed> $config 配置参数
     * @param  string|null          $name   配置名（空则合并到顶层）
     * @return array<string, mixed>
     */
    public function set(array $config, ?string $name = null): array
    {
        if (empty($name)) {
            $this->config = array_merge($this->config, array_change_key_case($config));

            return $this->config;
        }

        if (isset($this->config[$name]) && is_array($this->config[$name])) {
            $result = array_merge($this->config[$name], $config);
        } else {
            $result = $config;
        }

        $this->config[$name] = $result;

        return $result;
    }
}
