<?php

declare(strict_types=1);

use Spatie\Prometheus\Http\Middleware\AllowIps;

/*
 * Метрики Horizon и очередей для Prometheus и дашборд Grafana «Queues» (sushi-market/horizon-prometheus-grafana).
 * Метрики отдаёт /metrics пакета spatie/laravel-prometheus с его префиксом (prometheus.default_namespace),
 * поэтому у него должно быть 'cache' => null: счётчики публикуют абсолютные значения на каждый сбор.
 */
return [
    /*
     * Попытки выполнения джобов со стороны воркеров: queue_jobs_total, гистограммы queue_job_duration_seconds
     * и queue_job_wait_seconds, queue_job_duration_min_seconds и queue_job_duration_max_seconds. Воркеры пишут
     * их в Redis, /metrics читает оттуда же, поэтому значение должно совпадать в app и horizon. Состояние
     * очередей и Horizon собирается всегда
     */
    'enabled' => env('HPG_ENABLED', true),

    // Redis для счётчиков попыток: общий для app и horizon
    'redis_connection' => env('HPG_REDIS_CONNECTION', 'default'),

    // Границы bucket-ов, секунды, общие для длительности попытки и ожидания в очереди.
    // Менять можно: накопленные данные хранятся некумулятивно и не портятся
    'duration_buckets' => [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 120, 300, 600, 1800, 3600],

    // Сколько джобов из головы каждой очереди просматривать для queue_jobs_pending по классам;
    // остаток идёт в job_class="(не просмотрено)", 0 — не просматривать.
    // Просмотр идёт Lua-скриптом при каждом сборе метрик, и всё это время Redis заблокирован.
    // Цена растёт с объёмом payload: перед увеличением проверьте LLEN и MEMORY USAGE очередей
    'pending_scan_limit' => (int) env('HPG_PENDING_SCAN_LIMIT', 1000),

    /*
     * JSON-списки джобов для таблиц дашборда: GET <prefix>/{pending,completed,failed,delayed}-jobs.
     * Рядом с /metrics и с тем же доступом: в nginx или ingress закрывать нужно весь префикс /metrics
     */
    'routes' => [
        'prefix' => 'metrics/horizon',
        'middleware' => [AllowIps::class],
    ],

    // Адрес дашборда «Queues» в Grafana этого окружения: по нему в меню Horizon после Dashboard появляется
    // пункт «Grafana». Без адреса пункта нет.
    // Например: HPG_GRAFANA_URL=https://grafana.example.com/d/horizon/queues,
    // локально — HPG_GRAFANA_URL=http://localhost:3000/d/horizon/queues (порт — GRAFANA_PORT)
    'grafana_url' => env('HPG_GRAFANA_URL'),

    // Адрес сервиса синтеза речи Piper (piper.http_server: POST /synthesize с {"text": …} → WAV): дашборд произносит
    // по нему сработавшие алерты. Браузер с дашбордом ходит туда сам, поэтому адрес должен открываться с его машины,
    // а сервис — разрешать CORS для адреса Grafana. Без адреса — только сигнал. Например: HPG_TTS_URL=https://tts.example.com
    'tts_url' => env('HPG_TTS_URL'),
];
