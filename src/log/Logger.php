<?php

declare(strict_types=1);

namespace Lychee\log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * PSR-3 日志记录器。
 *
 * 将日志格式化后交给驱动写入。支持按级别过滤、上下文插值和频道隔离。
 */
class Logger extends AbstractLogger
{
    /** 日志级别到数值的映射，用于过滤 */
    private const LEVELS = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT     => 1,
        LogLevel::CRITICAL  => 2,
        LogLevel::ERROR     => 3,
        LogLevel::WARNING   => 4,
        LogLevel::NOTICE    => 5,
        LogLevel::INFO      => 6,
        LogLevel::DEBUG     => 7,
    ];

    public function __construct(
        private readonly LogManager $manager,
        private readonly string $channel,
        private readonly string $level = LogLevel::DEBUG,
    ) {
    }

    /**
     * 记录一条日志。
     *
     * @param mixed             $level   PSR-3 日志级别
     * @param string|Stringable $message 日志消息
     * @param array             $context 上下文数据
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $level = (string) $level;

        // 级别过滤：仅记录不低于当前级别的日志
        if (!$this->isLevelEnabled($level)) {
            return;
        }

        $formatted = $this->format($level, (string) $message, $context);

        $this->manager->driver($this->channel)->write($formatted);
    }

    /**
     * 判断指定级别是否在当前配置下被记录。
     */
    private function isLevelEnabled(string $level): bool
    {
        $configured = self::LEVELS[$this->level] ?? PHP_INT_MAX;
        $current    = self::LEVELS[$level]       ?? PHP_INT_MAX;

        return $current <= $configured;
    }

    /**
     * 格式化单条日志。
     *
     * 格式：[2026-09-16 10:30:00] channel.INFO: message {"key":"value"}
     */
    private function format(string $level, string $message, array $context): string
    {
        // 上下文插值：将 {key} 替换为 context 中的值
        if (preg_match_all('/\{(\w+)\}/', $message, $matches)) {
            foreach ($matches[1] as $key) {
                if (array_key_exists($key, $context)) {
                    $value   = $context[$key];
                    $message = str_replace(
                        '{' . $key . '}',
                        is_scalar($value) || $value instanceof Stringable ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE),
                        $message
                    );
                }
            }
        }

        $timestamp = date('Y-m-d H:i:s');
        $levelTag  = strtoupper($level);
        $channel   = $this->channel;

        $line = "[{$timestamp}] {$channel}.{$levelTag}: {$message}";

        // 剩余上下文作为 JSON 附加
        $extra = [];
        foreach ($context as $key => $value) {
            if (!str_contains($message, '{' . $key . '}')) {
                $extra[$key] = $value;
            }
        }

        if (!empty($extra)) {
            $line .= ' ' . json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $line . PHP_EOL;
    }
}
