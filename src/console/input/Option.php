<?php

declare(strict_types=1);

namespace Lychee\console\input;

use InvalidArgumentException;

/**
 * 控制台命令选项定义。
 */
class Option
{
    public const VALUE_NONE      = 1;
    public const VALUE_REQUIRED  = 2;
    public const VALUE_OPTIONAL  = 4;
    public const VALUE_IS_ARRAY  = 8;
    public const VALUE_NEGATABLE = 16;

    public function __construct(
        private string $name,
        private string $shortcut = '',
        private int $mode = self::VALUE_NONE,
        private string $description = '',
        private mixed $default = null,
    ) {
        if ($mode < 1 || $mode > 31) {
            throw new InvalidArgumentException(sprintf('Option mode "%s" is not valid.', $mode));
        }

        if ($this->isArray()) {
            $this->default = [];
        } elseif ($this->isValueNone()) {
            $this->default = false;
        } else {
            $this->default = $default;
        }
    }

    public function getShortcut(): string
    {
        return $this->shortcut;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function acceptValue(): bool
    {
        return $this->isValueRequired() || $this->isValueOptional();
    }

    public function isValueRequired(): bool
    {
        return self::VALUE_REQUIRED === (self::VALUE_REQUIRED & $this->mode);
    }

    public function isValueOptional(): bool
    {
        return self::VALUE_OPTIONAL === (self::VALUE_OPTIONAL & $this->mode);
    }

    public function isValueNone(): bool
    {
        return self::VALUE_NONE === (self::VALUE_NONE & $this->mode);
    }

    public function isArray(): bool
    {
        return self::VALUE_IS_ARRAY === (self::VALUE_IS_ARRAY & $this->mode);
    }

    public function setDefault(mixed $default): void
    {
        if ($this->isArray()) {
            $this->default = (array) $default;
        } elseif ($this->isValueNone()) {
            $this->default = false;
        } else {
            $this->default = $default;
        }
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
