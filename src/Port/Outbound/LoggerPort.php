<?php

declare(strict_types=1);

namespace FideX\Port\Outbound;

/**
 * Outbound Port: Logger
 *
 * Minimal logging interface — keeps domain core free of PSR-3 dependency.
 * Implementation: FileLogger (writes to storage/logs/fidex.log)
 */
interface LoggerPort
{
    public function info(string $message, array $context = []): void;

    public function warning(string $message, array $context = []): void;

    public function error(string $message, array $context = []): void;

    public function debug(string $message, array $context = []): void;
}
