<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Prometheus;

use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Адрес панели Horizon этого окружения: по нему дашборд «Queues» строит ссылку «Horizon».
 * Строится из конфига, а не из url(): /metrics запрашивает Prometheus по внутреннему адресу.
 *
 * horizon.domain — полный хост (Horizon передаёт его в domain группы маршрутов), без него Horizon
 * на хосте приложения; horizon.path — префикс маршрутов. Схема и порт — из app.url.
 *
 * tts_url — адрес сервиса синтеза речи (horizon-prometheus-grafana.tts_url): по нему дашборд произносит
 * сработавшие алерты. Пусто — только звуковой сигнал.
 */
class HorizonInfoCollector implements Collector
{
    public function register(): void
    {
        Prometheus::addGauge('Horizon info')
            ->name('horizon_info')
            ->helpText('Адрес панели Horizon и сервиса синтеза речи для дашборда: 1 с метками url и tts_url')
            ->labels(['url', 'tts_url'])
            ->value(fn (): array => [[1, [$this->url(), rtrim((string) config('horizon-prometheus-grafana.tts_url'), '/')]]]);
    }

    private function url(): string
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $domain = config('horizon.domain');
        $port = parse_url($appUrl, PHP_URL_PORT);
        $base = $domain
            ? (parse_url($appUrl, PHP_URL_SCHEME) ?: 'https') . '://' . $domain . ($port ? ':' . $port : '')
            : $appUrl;

        return $base . '/' . trim((string) config('horizon.path'), '/');
    }
}
