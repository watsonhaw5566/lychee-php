<?php

declare(strict_types=1);

namespace Lychee\console;

use Lychee\console\input\Argument;
use Lychee\console\input\Definition;
use Lychee\console\input\Option;
use Lychee\container\Container;
use ReflectionMethod;

/**
 * 控制台命令基类。
 *
 * 子类可实现 handle() 方法（可注入容器依赖），或直接覆写 execute()。
 */
class Command
{
    protected string $name = '';

    protected string $description = '';

    protected string $help = '';

    protected ?Definition $definition = null;

    protected ?Input $input = null;

    protected ?Output $output = null;

    protected Container $app;

    public function __construct()
    {
        $this->definition = new Definition();
    }

    protected function configure()
    {
    }

    public function callConfigure(): void
    {
        $reflection = new ReflectionMethod($this, 'configure');
        $reflection->setAccessible(true);
        $reflection->invoke($this);
    }

    protected function execute(Input $input, Output $output)
    {
        $this->input  = $input;
        $this->output = $output;

        if (method_exists($this, 'handle')) {
            $result = $this->app->invoke([$this, 'handle']);
            if (is_int($result)) {
                return $result;
            }
        }

        return 0;
    }

    public function run(Input $input, Output $output): int
    {
        if ($this->definition !== null) {
            $input->bind($this->definition);
        }

        $reflection = new ReflectionMethod($this, 'execute');
        $reflection->setAccessible(true);

        return (int) $reflection->invoke($this, $input, $output);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getHelp(): string
    {
        return $this->help;
    }

    public function setHelp(string $help): static
    {
        $this->help = $help;

        return $this;
    }

    public function addArgument(string $name, int $mode = null, string $description = '', mixed $default = null): static
    {
        $this->definition?->addArgument(new Argument($name, $mode ?? Argument::OPTIONAL, $description, $default));

        return $this;
    }

    public function addOption(string $name, ?string $shortcut = null, int $mode = null, string $description = '', mixed $default = null): static
    {
        $this->definition?->addOption(new Option($name, $shortcut ?? '', $mode ?? Option::VALUE_NONE, $description, $default));

        return $this;
    }

    public function getDefinition(): ?Definition
    {
        return $this->definition;
    }

    public function setApp(Container $app): void
    {
        $this->app = $app;
    }

    public function setInput(Input $input): void
    {
        $this->input = $input;
    }

    public function setOutput(Output $output): void
    {
        $this->output = $output;
    }

    public function getInput(): ?Input
    {
        return $this->input;
    }

    public function getOutput(): ?Output
    {
        return $this->output;
    }
}
