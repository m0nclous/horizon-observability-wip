<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Prometheus;

use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;
use SushiMarket\HorizonPrometheusGrafana\HorizonQueues;

/**
 * Пороги ожидания очередей из horizon.waits — те же, по которым Horizon шлёт LongWaitDetected:
 * без своего порога — 60 секунд, 0 — проверка выключена, такая очередь не отдаётся.
 * Дашборд «Queues» сравнивает с ними возраст самого старого ожидающего джоба очереди.
 */
class HorizonWaitsCollector implements Collector
{
    private const DEFAULT_WAIT_SECONDS = 60;

    public function register(): void
    {
        Prometheus::addGauge('Horizon wait threshold')
            ->name('horizon_wait_threshold_seconds')
            ->helpText('Порог ожидания очереди из horizon.waits, секунды')
            ->labels(['connection', 'queue'])
            ->value(fn (): array => $this->thresholds());
    }

    /**
     * @return list<array{0: int, 1: list<string>}>
     */
    private function thresholds(): array
    {
        $result = [];

        foreach (app(HorizonQueues::class)->byConnection() as $connection => $queues) {
            foreach ($queues as $queue) {
                $seconds = (int) (config("horizon.waits.{$connection}:{$queue}") ?? self::DEFAULT_WAIT_SECONDS);

                if ($seconds > 0) {
                    $result[] = [$seconds, [$connection, $queue]];
                }
            }
        }

        return $result;
    }
}
