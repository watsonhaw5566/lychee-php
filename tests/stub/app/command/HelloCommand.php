<?php

declare(strict_types=1);

namespace Tests\stub\app\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\Output;

class HelloCommand extends Command
{
    protected string $name = 'stub:hello';

    protected string $description = 'Stub command for testing';

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);
    }

    protected function execute(Input $input, Output $output): int
    {
        $output->writeln('hello from stub');

        return 0;
    }
}
