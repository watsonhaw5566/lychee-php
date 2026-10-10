<?php

declare(strict_types=1);

namespace Lychee\queue\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\input\Option;
use Lychee\console\Output;
use Lychee\queue\QueueManager;
use RuntimeException;

/**
 * 队列监听命令。
 *
 * 用法：
 *   php lee queue:listen --connection=redis --queue=default --sleep=3 --tries=3 --memory=128 --timeout=60
 *
 * 父进程常驻，每个任务都在全新的 queue:work --once 子进程中执行：
 * 子进程每次重新引导框架、重新加载代码，因此修改任务代码后无需重启监听进程，
 * 适合生产环境配合 Supervisor 使用。代价是每个任务需支付一次框架引导开销，
 * 吞吐量低于常驻 queue:work，高并发场景应使用 queue:work。
 */
class ListenCommand extends Command
{
    protected string $name = 'queue:listen';

    protected string $description = 'Listen to a queue and run every job in a fresh worker process';

    private bool $stopped = false;

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);

        $this->addOption('connection', 'c', Option::VALUE_OPTIONAL, 'The queue connection to use', null);
        $this->addOption('queue', null, Option::VALUE_OPTIONAL, 'The queue to listen on', null);
        $this->addOption('sleep', null, Option::VALUE_OPTIONAL, 'Seconds to wait before polling again when the queue is empty', '3');
        $this->addOption('tries', null, Option::VALUE_OPTIONAL, 'Number of times to attempt a job before logging it failed', '1');
        $this->addOption('memory', null, Option::VALUE_OPTIONAL, 'Memory limit in MB for the listener; it exits for supervisor restart when exceeded', '128');
        $this->addOption('timeout', null, Option::VALUE_OPTIONAL, 'Maximum seconds a worker child may run before being killed (0 = no limit)', '60');
    }

    protected function execute(Input $input, Output $output): int
    {
        /** @var QueueManager $manager */
        $manager    = $this->app->get(QueueManager::class);
        $connection = $input->getOption('connection') ?: $manager->getDefaultDriver();
        $queue      = $input->getOption('queue');
        $sleep      = max(0, (int) $input->getOption('sleep'));
        $tries      = max(1, (int) $input->getOption('tries'));
        $memoryMb   = max(16, (int) $input->getOption('memory'));
        $timeout    = max(0, (int) $input->getOption('timeout'));

        $command = $this->buildCommand($connection, $queue, $tries);

        $output->writeln("<info>Listening for jobs on connection [{$connection}]</info>");
        $output->writeln('<info>Every job runs in a fresh process — code changes take effect immediately.</info>');

        $this->registerSignalHandler();

        while ($this->shouldContinue()) {
            $result = $this->runWorker($command, $timeout);

            if ($result['timeout']) {
                $output->writeln("<error>Worker exceeded {$timeout}s and was killed; the job will be retried after retry_after.</error>");
            }

            if ($this->memoryExceeded($memoryMb)) {
                $output->writeln("<comment>Memory limit {$memoryMb}MB exceeded; listener exiting for supervisor restart.</comment>");

                return 0;
            }

            // 子进程未取到任务时休眠，避免空队列时高频拉起进程
            if ($result['code'] === WorkCommand::EXIT_EMPTY) {
                sleep($sleep);
            }
        }

        return 0;
    }

    /**
     * 构造 queue:work --once 子进程命令。
     *
     * 使用数组形式交给 proc_open，参数无需手工转义。
     *
     * @return list<string>
     */
    protected function buildCommand(string $connection, ?string $queue, int $tries): array
    {
        $command = [
            $this->phpBinary(),
            $this->entryScript(),
            'queue:work',
            '--once',
            '--connection=' . $connection,
            '--tries=' . $tries,
        ];

        if ($queue !== null && $queue !== '') {
            $command[] = '--queue=' . $queue;
        }

        return $command;
    }

    protected function phpBinary(): string
    {
        return PHP_BINARY;
    }

    /**
     * 解析控制台入口脚本路径（即启动本命令时的 lee 脚本）。
     */
    protected function entryScript(): string
    {
        $script = (string) ($_SERVER['argv'][0] ?? '');
        $path   = $script !== '' ? realpath($script) : false;

        if ($path === false) {
            throw new RuntimeException(
                'Unable to locate the console entry script. Run the listener through your console entry, e.g. "php lee queue:listen".'
            );
        }

        return $path;
    }

    /**
     * 拉起一个 worker 子进程并等待结束，实时透传其输出。
     *
     * @param  list<string>  $command
     * @return array{code: int, timeout: bool}
     */
    protected function runWorker(array $command, int $timeout): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start worker process. Make sure proc_open is not disabled.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $started  = microtime(true);
        $timedOut = false;
        $exitCode = -1;

        try {
            while (true) {
                $this->streamOutput($pipes[1], $pipes[2]);

                $status = proc_get_status($process);

                if (!$status['running']) {
                    $exitCode = is_int($status['exitcode']) ? $status['exitcode'] : -1;
                    break;
                }

                if ($this->stopped) {
                    $exitCode = $this->stopProcess($process);
                    break;
                }

                if ($timeout > 0 && microtime(true) - $started >= $timeout) {
                    $timedOut = true;
                    $exitCode = $this->stopProcess($process);
                    break;
                }

                usleep(200_000);
            }
        } finally {
            $this->streamOutput($pipes[1], $pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        return ['code' => $exitCode, 'timeout' => $timedOut];
    }

    /**
     * 先 SIGTERM 优雅终止子进程，2 秒内未退出则 SIGKILL 强杀。
     */
    private function stopProcess(mixed $process): int
    {
        proc_terminate($process, 15);

        $deadline = time() + 2;

        while (time() < $deadline) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                return is_int($status['exitcode']) ? $status['exitcode'] : -1;
            }

            usleep(200_000);
        }

        proc_terminate($process, 9);

        return -1;
    }

    /**
     * 将子进程已产生的输出实时写到当前标准输出/错误。
     *
     * @param resource $stdout
     * @param resource $stderr
     */
    private function streamOutput($stdout, $stderr): void
    {
        $out = stream_get_contents($stdout);
        if ($out !== false && $out !== '') {
            fwrite(STDOUT, $out);
        }

        $err = stream_get_contents($stderr);
        if ($err !== false && $err !== '') {
            fwrite(STDERR, $err);
        }
    }

    protected function memoryExceeded(int $memoryMb): bool
    {
        return memory_get_usage(true) >= $memoryMb * 1024 * 1024;
    }

    /**
     * 监听循环是否继续。收到 SIGTERM/SIGINT 后停止。
     */
    protected function shouldContinue(): bool
    {
        return !$this->stopped;
    }

    private function registerSignalHandler(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->stopped = true;
        });
        pcntl_signal(SIGINT, function (): void {
            $this->stopped = true;
        });
    }
}
