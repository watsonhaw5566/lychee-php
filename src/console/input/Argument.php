<?php

declare(strict_types=1);

namespace Lychee\console\input;

use InvalidArgumentException;
use LogicException;

/**
 * 控制台命令参数定义。
 */
class Argument
{
    public const REQUIRED = 1;
    public const OPTIONAL = 2;
    public const IS_ARRAY = 4;

    public function __construct(
        private string $name,
        private int $mode = self::OPTIONAL,
        private string $description = '',
        private mixed $default = null,
    ) {
        if ($mode < 1 || $mode > 7) {
            throw new InvalidArgumentException(sprintf('Argument mode "%s" is not valid.', $mode));
        }

        if ($this->isRequired() && null !== $default) {
            throw new LogicException('Cannot set a default value for required arguments.');
        }

        if ($this->isArray()) {
            $this->default = [];
        } else {
            $this->default = $default;
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isRequired(): bool
    {
        return self::REQUIRED === (self::REQUIRED & $this->mode);
    }

    public function isArray(): bool
    {
        return self::IS_ARRAY === (self::IS_ARRAY & $this->mode);
    }

    public function setDefault(mixed $default): void
    {
        if ($this->isArray()) {
            $this->default = (array) $default;
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
