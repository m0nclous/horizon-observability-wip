<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Spatie\Prometheus\Collectors\Horizon\HorizonStatusCollector;
use Spatie\Prometheus\Facades\Prometheus;
use SushiMarket\HorizonPrometheusGrafana\Console\QueueLoadTestCommand;
use SushiMarket\HorizonPrometheusGrafana\Http\Controllers\HorizonJobsController;
use SushiMarket\HorizonPrometheusGrafana\Http\Middleware\AddGrafanaLinkToHorizon;
use SushiMarket\HorizonPrometheusGrafana\Http\Middleware\ForceJsonResponse;
use SushiMarket\HorizonPrometheusGrafana\Prometheus\HorizonInfoCollector;
use SushiMarket\HorizonPrometheusGrafana\Prometheus\HorizonSupervisorsCollector;
use SushiMarket\HorizonPrometheusGrafana\Prometheus\HorizonWaitsCollector;
use SushiMarket\HorizonPrometheusGrafana\Prometheus\HorizonWorkloadCollector;
use SushiMarket\HorizonPrometheusGrafana\Prometheus\QueueBatchesCollector;
use SushiMarket\HorizonPrometheusGrafana\Prometheus\QueueJobClassesCollector;
use SushiMarket\HorizonPrometheusGrafana\Prometheus\QueueJobsCollector;
use SushiMarket\HorizonPrometheusGrafana\Prometheus\QueueStateCollector;
use SushiMarket\HorizonPrometheusGrafana\Prometheus\ScrapeCache;

/**
 * Метрики Horizon и очередей на /metrics (spatie/laravel-prometheus), запись попыток воркерами,
 * JSON-списки джобов для дашборда и пункт «Grafana» в меню Horizon.
 *
 * Коллекторы пакета никогда не ломают /metrics: сбой источника перехватывается и пишется в лог один раз
 * за сбор, а метрики этого источника в ответ не попадают. У HorizonStatusCollector из spatie такой защиты нет:
 * если Redis Horizon недоступен, /metrics отвечает 500.
 */
class HorizonPrometheusGrafanaServiceProvider extends ServiceProvider
{
    private const CONFIG = 'horizon-prometheus-grafana';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/' . self::CONFIG . '.php', self::CONFIG);

        // Коллекторы живут весь процесс (под Octane — между запросами), поэтому данные,
        // которые читаются один раз за сбор /metrics, хранятся в scoped-объекте
        $this->app->scoped(ScrapeCache::class);

        // Horizon регистрирует свои маршруты в boot() с middleware из конфига: пункт «Grafana» в меню
        // добавляется раньше, при регистрации провайдеров
        $this->app['config']->push('horizon.middleware', AddGrafanaLinkToHorizon::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/' . self::CONFIG . '.php' => config_path(self::CONFIG . '.php'),
        ], self::CONFIG . '-config');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', self::CONFIG);

        $this->registerCollectors();
        $this->registerRoutes();

        // Попытки джобов пишут воркеры Horizon: подписчик сам ничего не делает, если запись выключена
        Event::subscribe(QueueJobMetricsSubscriber::class);

        if ($this->app->runningInConsole()) {
            $this->commands([QueueLoadTestCommand::class]);
        }
    }

    private function registerCollectors(): void
    {
        Prometheus::registerCollectorClasses([
            HorizonStatusCollector::class,
            HorizonWorkloadCollector::class,
            HorizonSupervisorsCollector::class,
            HorizonInfoCollector::class,
            HorizonWaitsCollector::class,
            // Состояние Redis-очередей из config/horizon.php
            QueueStateCollector::class,
            QueueJobClassesCollector::class,
            QueueBatchesCollector::class,
        ]);

        if (config(self::CONFIG . '.enabled')) {
            Prometheus::registerCollectorClasses([QueueJobsCollector::class]);
        }
    }

    /**
     * Ожидающие, выполненные, упавшие и отложенные джобы для таблиц дашборда
     */
    private function registerRoutes(): void
    {
        Route::middleware([...config(self::CONFIG . '.routes.middleware', []), ForceJsonResponse::class])
            ->prefix(config(self::CONFIG . '.routes.prefix'))
            ->group(function (): void {
                Route::get('pending-jobs', [HorizonJobsController::class, 'pending']);
                Route::get('completed-jobs', [HorizonJobsController::class, 'completed']);
                Route::get('failed-jobs', [HorizonJobsController::class, 'failed']);
                Route::get('delayed-jobs', [HorizonJobsController::class, 'delayed']);
            });
    }
}
