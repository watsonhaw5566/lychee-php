<?php

declare(strict_types=1);

namespace Lychee\console;

use Lychee\console\input\Definition;
use RuntimeException;

/**
 * 控制台输入。
 *
 * 解析命令行参数，提供 getArgument/getOption 等接口。
 */
class Input
{
    protected ?Definition $definition = null;

    /** @var array<string, mixed> */
    protected array $arguments = [];

    /** @var array<string, mixed> */
    protected array $options = [];

    /** @var string[] */
    protected array $tokens;

    protected bool $interactive = true;

    /**
     * @param string[]|null $argv 命令行参数（不含脚本名）
     */
    public function __construct(?array $argv = null)
    {
        if ($argv === null) {
            $argv = array_slice($_SERVER['argv'] ?? [], 1);
        }

        $this->tokens = $argv;
    }

    public function bind(Definition $definition): void
    {
        $this->definition = $definition;
        $this->arguments  = [];
        $this->options    = [];

        foreach ($definition->getArguments() as $name => $argument) {
            $this->arguments[$name] = $argument->getDefault();
        }
        foreach ($definition->getOptions() as $name => $option) {
            $this->options[$name] = $option->getDefault();
        }

        $this->parse();
    }

    public function parse(): void
    {
        if ($this->definition === null) {
            return;
        }

        $args       = array_values($this->definition->getArguments());
        $argIndex   = 0;
        $expecting  = false;
        $expectName = '';

        foreach ($this->tokens as $token) {
            if ($expecting) {
                $this->options[$expectName] = $token;
                $expecting                  = false;
                continue;
            }

            if (str_starts_with($token, '--')) {
                $name = substr($token, 2);
                if ($name === '') {
                    continue;
                }

                if (str_contains($name, '=')) {
                    [$name, $value] = explode('=', $name, 2);
                    if ($this->definition->hasOption($name)) {
                        $this->options[$name] = $value;
                    }
                } else {
                    if ($this->definition->hasOption($name)) {
                        $option = $this->definition->getOption($name);
                        if ($option->acceptValue()) {
                            $expecting  = true;
                            $expectName = $name;
                        } else {
                            $this->options[$name] = true;
                        }
                    }
                }
            } elseif (str_starts_with($token, '-') && strlen($token) > 1) {
                $shortcut = substr($token, 1);
                if ($this->definition->hasShortcut($shortcut)) {
                    $name   = $this->definition->getOptionByShortcut($shortcut)->getName();
                    $option = $this->definition->getOption($name);
                    if ($option->acceptValue()) {
                        $expecting  = true;
                        $expectName = $name;
                    } else {
                        $this->options[$name] = true;
                    }
                }
            } else {
                if (isset($args[$argIndex])) {
                    $arg = $args[$argIndex];
                    if ($arg->isArray()) {
                        $this->arguments[$arg->getName()][] = $token;
                    } else {
                        $this->arguments[$arg->getName()] = $token;
                        $argIndex++;
                    }
                }
            }
        }
    }

    public function validate(): void
    {
        if ($this->definition) {
            foreach ($this->definition->getArguments() as $name => $argument) {
                if ($argument->isRequired() && null === $this->arguments[$name]) {
                    throw new RuntimeException(sprintf('Not enough arguments (missing: "%s").', $name));
                }
            }
        }
    }

    public function getFirstArgument(): ?string
    {
        foreach ($this->tokens as $token) {
            if ($token && '-' !== $token[0]) {
                return $token;
            }
        }

        return null;
    }

    public function hasParameterOption(string|array $values): bool
    {
        foreach ($this->tokens as $token) {
            if (in_array($token, (array) $values, true)) {
                return true;
            }
        }

        return false;
    }

    public function getParameterOption(string|array $values, mixed $default = false): mixed
    {
        $values = (array) $values;

        foreach ($this->tokens as $index => $token) {
            if (in_array($token, $values, true)) {
                return $this->tokens[$index + 1] ?? $default;
            }

            foreach ($values as $value) {
                if (str_starts_with($token, $value . '=')) {
                    return substr($token, strlen($value) + 1);
                }
            }
        }

        return $default;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getArgument(string $name): mixed
    {
        return $this->arguments[$name] ?? null;
    }

    public function setArgument(string $name, mixed $value): void
    {
        $this->arguments[$name] = $value;
    }

    public function hasArgument(string $name): bool
    {
        return array_key_exists($name, $this->arguments);
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getOption(string $name): mixed
    {
        return $this->options[$name] ?? null;
    }

    public function setOption(string $name, mixed $value): void
    {
        $this->options[$name] = $value;
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    public function setInteractive(bool $interactive): void
    {
        $this->interactive = $interactive;
    }

    public function escapeToken(string $token): string
    {
        return preg_match('{^[\w-]+$}', $token) ? $token : escapeshellarg($token);
    }

    public function __toString(): string
    {
        return implode(' ', array_map([$this, 'escapeToken'], $this->tokens));
    }

    /**
     * @return string[]
     */
    public function getTokens(): array
    {
        return $this->tokens;
    }
}
