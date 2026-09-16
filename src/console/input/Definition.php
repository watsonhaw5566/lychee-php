<?php

declare(strict_types=1);

namespace Lychee\console\input;

use InvalidArgumentException;

/**
 * 控制台输入定义（参数与选项集合）。
 */
class Definition
{
    /** @var array<string, Argument> */
    private array $arguments = [];

    /** @var array<string, Option> */
    private array $options = [];

    /** @var array<string, string> */
    private array $shortcutToName = [];

    /**
     * @param array<Argument|Option> $definition
     */
    public function __construct(array $definition = [])
    {
        foreach ($definition as $item) {
            if ($item instanceof Option) {
                $this->addOption($item);
            } else {
                $this->addArgument($item);
            }
        }
    }

    public function addArgument(Argument $argument): self
    {
        $this->arguments[$argument->getName()] = $argument;

        return $this;
    }

    public function getArgument(string|int $name): Argument
    {
        if (!$this->hasArgument($name)) {
            throw new InvalidArgumentException(sprintf('The "%s" argument does not exist.', $name));
        }

        return $this->arguments[$name];
    }

    public function hasArgument(string|int $name): bool
    {
        return isset($this->arguments[$name]);
    }

    /**
     * @return array<string, Argument>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function addOption(Option $option): self
    {
        $this->options[$option->getName()] = $option;

        if ($shortcut = $option->getShortcut()) {
            $this->shortcutToName[$shortcut] = $option->getName();
        }

        return $this;
    }

    public function getOption(string $name): Option
    {
        if (!$this->hasOption($name)) {
            throw new InvalidArgumentException(sprintf('The "%s" option does not exist.', $name));
        }

        return $this->options[$name];
    }

    public function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }

    public function hasShortcut(string $shortcut): bool
    {
        return isset($this->shortcutToName[$shortcut]);
    }

    public function getOptionByShortcut(string $shortcut): Option
    {
        if (!$this->hasShortcut($shortcut)) {
            throw new InvalidArgumentException(sprintf('The "-%s" option does not exist.', $shortcut));
        }

        return $this->options[$this->shortcutToName[$shortcut]];
    }

    /**
     * @return array<string, Option>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
