<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana;

/**
 * Снимок одной Redis-очереди на момент сбора метрик.
 */
final readonly class QueueState
{
    /**
     * @param int $delayedDue отложенные, срок которых наступил, но ни один воркер очереди их ещё не забрал:
     *        Laravel переносит их в очередь только в pop(). Входят в $delayed
     * @param int|null $oldestReadyAt с какого момента ждёт самый старый готовый джоб, unix-время:
     *        головной — от постановки в очередь (pushedAt, иначе createdAt; задержка при отправке
     *        и прошлые попытки сюда входят), отложенный с наступившим сроком — от срока
     * @param array<string, int> $pendingByClass ожидающие по классам; сумма равна $pending
     * @param array<string, int> $reservedByClass взятые воркерами по классам; сумма — всем взятым джобам очереди
     */
    public function __construct(
        public string $connection,
        public string $queue,
        public int $pending,
        public int $delayed,
        public int $delayedDue,
        public ?int $oldestReadyAt,
        public array $pendingByClass,
        public array $reservedByClass,
    ) {
    }
}
