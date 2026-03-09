<?php

declare(strict_types=1);

namespace FideX\Adapter\Outbound\Logger;

use FideX\Port\Outbound\LoggerPort;

/**
 * File Logger Adapter
 *
 * Writes structured log lines to a file.
 * Zero external dependencies — works everywhere.
 *
 * Log format: [2026-03-09T10:00:00Z] [INFO] Message {"key":"value"}
 */
final class FileLogger implements LoggerPort
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private int $minLevel;

    public function __construct(
        private readonly string $logPath,
        string $logLevel = 'info',
    ) {
        $this->minLevel = self::LEVELS[strtolower($logLevel)] ?? 1;
        $dir = dirname($logPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('DEBUG', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $levelInt = self::LEVELS[strtolower($level)] ?? 1;
        if ($levelInt < $this->minLevel) {
            return;
        }

        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $contextStr = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        $line = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

        file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
    }
}
