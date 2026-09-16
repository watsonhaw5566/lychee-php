<?php

declare(strict_types=1);

namespace Lychee\console;

use Lychee\console\output\Formatter;

/**
 * 控制台输出。
 */
class Output
{
    public const VERBOSITY_QUIET        = 16;
    public const VERBOSITY_NORMAL       = 32;
    public const VERBOSITY_VERBOSE      = 64;
    public const VERBOSITY_VERY_VERBOSE = 128;
    public const VERBOSITY_DEBUG        = 256;

    protected int $verbosity;
    protected Formatter $formatter;

    public function __construct(int $verbosity = self::VERBOSITY_NORMAL, ?Formatter $formatter = null)
    {
        $this->verbosity = $verbosity;
        $this->formatter = $formatter ?? new Formatter();
    }

    public function write(string|array $messages, bool $newline = false, int $options = 0): void
    {
        foreach ((array) $messages as $message) {
            $this->doWrite($this->formatter->format($message) ?? $message, $newline);
        }
    }

    public function writeln(string|array $messages, int $options = 0): void
    {
        $this->write($messages, true, $options);
    }

    protected function doWrite(string $message, bool $newline): void
    {
        if ($this->verbosity <= self::VERBOSITY_QUIET) {
            return;
        }

        echo $message . ($newline ? PHP_EOL : '');
    }

    public function setVerbosity(int $level): void
    {
        $this->verbosity = $level;
    }

    public function getVerbosity(): int
    {
        return $this->verbosity;
    }

    public function setDecorated(bool $decorated): void
    {
        $this->formatter->setDecorated($decorated);
    }

    public function getFormatter(): Formatter
    {
        return $this->formatter;
    }
}
