<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Prometheus;

use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Супервизоры Horizon с фактическими настройками, а не значениями из config/horizon.php:
 * например, Horizon поднимает maxProcesses до числа очередей супервизора.
 *
 * Метка supervisor — имя без префикса мастера, чтобы серии не менялись при деплое.
 * Если мастеров несколько, процессы складываются, а у настроек берётся максимум.
 *
 * Дашборд «Queues» строит по processes и max_processes линию лимита воркеров очереди на «Pending by job»
 * и колонку «Utilization», а по info, min_processes и max_processes — заголовки таблиц супервизоров.
 */
class HorizonSupervisorsCollector implements Collector
{
    // Метрика => [опция супервизора, описание]
    private const SETTINGS = [
        'horizon_supervisor_min_processes' => ['minProcesses', 'Минимум процессов супервизора на каждую очередь'],
        'horizon_supervisor_max_processes' => ['maxProcesses', 'Потолок процессов супервизора на все его очереди'],
    ];

    public function register(): void
    {
        Prometheus::addGauge('Horizon supervisor processes')
            ->name('horizon_supervisor_processes')
            ->helpText('Процессы супервизора на очереди сейчас')
            ->labels(['supervisor', 'queue'])
            ->value(fn (): array => $this->processes());

        Prometheus::addGauge('Horizon supervisor info')
            ->name('horizon_supervisor_info')
            ->helpText('Режим балансировки (auto, simple, off) и соединение очередей супервизора: 1 на супервизор')
            ->labels(['supervisor', 'balance', 'connection'])
            ->value(fn (): array => $this->info());

        foreach (self::SETTINGS as $name => [$option, $help]) {
            Prometheus::addGauge($name)
                ->name($name)
                ->helpText($help)
                ->label('supervisor')
                ->value(fn (): array => $this->setting($option));
        }
    }

    /**
     * @return list<array{0: int, 1: list<string>}>
     */
    private function processes(): array
    {
        $result = [];

        foreach ($this->supervisors()['processes'] ?? [] as $supervisor => $queues) {
            foreach ($queues as $queue => $processes) {
                $result[] = [$processes, [$supervisor, $queue]];
            }
        }

        return $result;
    }

    /**
     * @return list<array{0: int, 1: list<string>}>
     */
    private function setting(string $option): array
    {
        $result = [];

        foreach ($this->supervisors()['settings'] ?? [] as $supervisor => $settings) {
            $result[] = [$settings[$option], [$supervisor]];
        }

        return $result;
    }

    /**
     * @return list<array{0: int, 1: list<string>}>
     */
    private function info(): array
    {
        $result = [];

        foreach ($this->supervisors()['info'] ?? [] as $supervisor => [$balance, $connection]) {
            $result[] = [1, [$supervisor, $balance, $connection]];
        }

        return $result;
    }

    /**
     * @return array{
     *     processes: array<string, array<string, int>>,
     *     settings: array<string, array<string, int>>,
     *     info: array<string, array{0: string, 1: string}>,
     * }
     */
    private function supervisors(): array
    {
        return app(ScrapeCache::class)->remember(self::class, function (): array {
            $processes = [];
            $settings = [];
            $info = [];

            foreach (app(SupervisorRepository::class)->all() as $supervisor) {
                $name = Str::afterLast($supervisor->name, ':');

                // Ключ — «соединение:очередь»
                foreach ($supervisor->processes ?? [] as $queue => $count) {
                    $queue = Str::after((string) $queue, ':');
                    $processes[$name][$queue] = ($processes[$name][$queue] ?? 0) + (int) $count;
                }

                foreach (self::SETTINGS as [$option]) {
                    $settings[$name][$option] = max(
                        $settings[$name][$option] ?? 0,
                        (int) ($supervisor->options[$option] ?? 0),
                    );
                }

                // 'balance' => false в config/horizon.php приходит как false: балансировки нет
                $balance = $supervisor->options['balance'] ?? 'off';
                $info[$name] = [
                    in_array($balance, ['auto', 'simple'], true) ? $balance : 'off',
                    (string) ($supervisor->options['connection'] ?? ''),
                ];
            }

            return ['processes' => $processes, 'settings' => $settings, 'info' => $info];
        });
    }
}
