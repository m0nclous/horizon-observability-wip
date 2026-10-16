<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Prometheus;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Данные одного сбора /metrics.
 *
 * Коллекторы spatie создаются один раз на процесс и под Octane живут между запросами,
 * а значения метрик читаются в замыканиях при каждом сборе. Класс зарегистрирован как
 * scoped, Octane пересоздаёт его на каждый запрос: источник читается один раз за сбор,
 * сколько бы метрик из него ни строилось. Кешировать в самих коллекторах нельзя.
 */
class ScrapeCache
{
    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * Сбой источника пишется в лог один раз за сбор, его метрики не публикуются:
     * /metrics общий для всего проекта и не должен падать из-за одного источника.
     */
    public function remember(string $source, Closure $read): mixed
    {
        if (array_key_exists($source, $this->values)) {
            return $this->values[$source];
        }

        try {
            return $this->values[$source] = $read();
        } catch (Throwable $exception) {
            Log::warning('QueueMetrics: не удалось собрать метрики', [
                'source' => $source,
                'exception' => $exception->getMessage(),
            ]);

            return $this->values[$source] = [];
        }
    }
}
