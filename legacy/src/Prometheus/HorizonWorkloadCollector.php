<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Prometheus;

use Laravel\Horizon\Contracts\WorkloadRepository;
use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Процессы Horizon на очередях так, как их видит Horizon: один WorkloadRepository::get() за сбор.
 *
 * Метка queue — имя из Horizon без «соединение:». У супервизора с balance=false это
 * список очередей через запятую: Horizon считает их одним пулом процессов.
 */
class HorizonWorkloadCollector implements Collector
{
    public function register(): void
    {
        Prometheus::addGauge('Horizon current processes')
            ->name('horizon_current_processes')
            ->helpText('Процессы Horizon на очереди сейчас')
            ->label('queue')
            ->value(fn (): array => $this->processes());
    }

    /**
     * @return list<array{0: int, 1: list<string>}>
     */
    private function processes(): array
    {
        return array_map(
            fn (array $queue): array => [$queue['processes'], [$queue['name']]],
            app(WorkloadRepository::class)->get(),
        );
    }
}
