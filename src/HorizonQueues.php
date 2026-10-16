<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;

/**
 * Очереди, которые `php artisan horizon` поднимет в текущем окружении, по соединениям.
 *
 * Берутся из config/horizon.php, а не из живого Horizon: метрики очередей должны
 * собираться и тогда, когда Horizon лежит и очереди растут.
 */
class HorizonQueues
{
    /**
     * Повторяет ProvisioningPlan::deploy(): окружение выбирается по шаблону Str::is
     * (первое совпадение), супервизоры с maxProcesses <= 0 не запускаются, без 'queue'
     * берётся очередь соединения по умолчанию (SupervisorOptions).
     *
     * @return array<string, list<string>>
     */
    public function byConnection(): array
    {
        $environment = (string) (config('horizon.env') ?? config('app.env'));

        /** @var Collection<string, SupervisorOptions>|null $supervisors */
        $supervisors = collect(ProvisioningPlan::get('metrics')->parsed)
            ->first(fn (Collection $supervisors, int|string $name): bool => Str::is((string) $name, $environment));

        return collect($supervisors ?? [])
            ->filter(fn (SupervisorOptions $options): bool => $options->maxProcesses > 0)
            ->groupBy(fn (SupervisorOptions $options): string => $options->connection)
            ->map(fn (Collection $group): array => $group
                ->flatMap(fn (SupervisorOptions $options): array => explode(',', (string) $options->queue))
                ->map(fn (string $queue): string => trim($queue))
                ->filter()
                ->unique()
                ->values()
                ->all())
            ->all();
    }
}
