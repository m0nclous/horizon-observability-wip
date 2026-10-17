<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger that keeps the records; bind it as `log` to capture the package's log lines.
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
