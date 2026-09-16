<?php

declare(strict_types=1);

namespace Lychee\queue;

use Lychee\container\Container;
use RuntimeException;
use Throwable;

/**
 * 队列任务抽象基类。
 *
 * 负责解析 payload、调用任务 handler、管理任务生命周期（delete/release/failed）。
 */
abstract class Job
{
    protected ?object $instance = null;

    /** @var array<string, mixed>|null */
    protected ?array $payload = null;

    protected Container $app;

    protected string $queue = '';

    protected string $connection = '';

    protected bool $deleted = false;

    protected bool $released = false;

    protected bool $failed = false;

    /**
     * 获取解码后的 payload，或 payload 中的某个字段。
     *
     * @return array<string, mixed>|mixed
     */
    public function payload(?string $name = null, mixed $default = null): mixed
    {
        if ($this->payload === null) {
            $decoded       = json_decode($this->getRawBody(), true);
            $this->payload = is_array($decoded) ? $decoded : [];
        }

        if ($name === null || $name === '') {
            return $this->payload;
        }

        return $this->payload[$name] ?? $default;
    }

    /**
     * 执行任务。
     */
    public function fire(): void
    {
        $instance   = $this->getResolvedJob();
        [, $method] = $this->getParsedJob();

        if (!is_object($instance) || !method_exists($instance, (string) $method)) {
            throw new RuntimeException(
                sprintf('Job handler method "%s" does not exist on "%s".', $method, get_class($instance))
            );
        }

        $instance->{$method}($this, $this->payload('data'));
    }

    /**
     * 任务失败时调用。
     */
    public function failed(Throwable $e): void
    {
        try {
            $instance = $this->getResolvedJob();
        } catch (Throwable) {
            return;
        }

        if (method_exists($instance, 'failed')) {
            $instance->failed($this->payload('data'), $e);
        }
    }

    /**
     * 删除任务（执行成功后调用）。
     */
    public function delete(): void
    {
        $this->deleted = true;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    /**
     * 释放任务（重新入队）。
     */
    public function release(int $delay = 0): void
    {
        $this->released = true;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function isDeletedOrReleased(): bool
    {
        return $this->isDeleted() || $this->isReleased();
    }

    public function markAsFailed(): void
    {
        $this->failed = true;
    }

    public function hasFailed(): bool
    {
        return $this->failed;
    }

    public function maxTries(): ?int
    {
        $value = $this->payload('maxTries');

        return $value === null ? null : (int) $value;
    }

    public function getName(): string
    {
        return (string) $this->payload('job');
    }

    public function getConnection(): string
    {
        return $this->connection;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * 获取任务 ID。
     */
    abstract public function getJobId(): mixed;

    /**
     * 获取已尝试次数。
     */
    abstract public function attempts(): int;

    /**
     * 获取原始 payload 字符串。
     */
    abstract public function getRawBody(): string;

    /**
     * 解析任务类名和方法名。
     *
     * @return array{0: string, 1: string}
     */
    protected function getParsedJob(): array
    {
        $job      = (string) $this->payload('job');
        $segments = explode('@', $job);

        if (count($segments) > 1) {
            return [(string) $segments[0], (string) $segments[1]];
        }

        return [(string) $segments[0], 'fire'];
    }

    /**
     * 从容器解析任务 handler。
     */
    protected function resolve(string $name): object
    {
        $class = str_contains($name, '\\')
            ? $name
            : rtrim($this->app->getNamespace(), '\\') . '\\job\\' . $name;

        $resolved = $this->app->make($class, [], true);

        if (!is_object($resolved)) {
            throw new RuntimeException(sprintf('Unable to resolve job handler "%s".', $class));
        }

        return $resolved;
    }

    /**
     * 获取解析后的任务 handler 实例。
     */
    public function getResolvedJob(): object
    {
        if ($this->instance === null) {
            [$class]        = $this->getParsedJob();
            $this->instance = $this->resolve($class);
        }

        return $this->instance;
    }
}
