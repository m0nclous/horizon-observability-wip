<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Support;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stringable;

/**
 * A PSR-3 logger that keeps the records; bind it as `log` to capture the package's log lines.
 *
 * It stands in for the log manager too: {@see self::channel()} returns the recorder itself, except for Laravel's
 * `deprecations` channel, whose lines (PHP deprecations of dependencies, e.g. predis 2.0 on PHP 8.2) are dropped, so
 * they never show up among the package's warnings.
 */
final class LogRecorder extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<array-key, mixed>}>
     */
    public array $records = [];

    /**
     * @param  array<array-key, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * The log manager's `channel()`, which Laravel's deprecation handler calls on the bound `log`.
     */
    public function channel(mixed $name = null): LoggerInterface
    {
        return $name === 'deprecations' ? new NullLogger : $this;
    }

    /**
     * @return list<string>
     */
    public function messages(?string $level = null): array
    {
        return array_values(array_map(
            static fn (array $record): string => $record['message'],
            array_filter($this->records, static fn (array $record): bool => $level === null || $record['level'] === $level),
        ));
    }
}
