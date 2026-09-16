<?php

declare(strict_types=1);

namespace Lychee\console;

use Lychee\container\Container;

/**
 * 控制台应用。
 *
 * 负责收集并运行命令。
 */
class Application
{
    /** @var array<string, Command> */
    protected array $commands = [];

    public function __construct(protected Container $app)
    {
    }

    /**
     * @param  array<class-string<Command>|Command> $commands
     */
    public function addCommands(array $commands): void
    {
        foreach ($commands as $command) {
            $this->addCommand($command);
        }
    }

    /**
     * @param  class-string<Command>|Command $command
     */
    public function addCommand(string|Command $command): void
    {
        if (is_string($command)) {
            $command = $this->app->make($command);
        }

        if ($command instanceof Command) {
            $command->callConfigure();
            $command->setApp($this->app);
            $this->commands[$command->getName()] = $command;
        }
    }

    /**
     * @return array<string, Command>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    public function getCommand(string $name): ?Command
    {
        return $this->commands[$name] ?? null;
    }

    public function run(?Input $input = null, ?Output $output = null): int
    {
        $input  ??= new Input();
        $output ??= new Output();

        $name = $input->getFirstArgument();

        if ($name === null || !isset($this->commands[$name])) {
            $output->writeln($this->getHelp());

            return $name === null ? 0 : 1;
        }

        $command = $this->commands[$name];

        $tokens   = array_slice($input->getTokens(), 1);
        $cmdInput = new Input($tokens);

        return $command->run($cmdInput, $output);
    }

    protected function getHelp(): string
    {
        $lines = ["<info>Available commands:</info>"];
        foreach ($this->commands as $name => $command) {
            $lines[] = sprintf("  <comment>%-20s</comment> %s", $name, $command->getDescription());
        }

        return implode(PHP_EOL, $lines);
    }
}
