<?php

declare(strict_types=1);

return [

    /*
    | Master switch. false = no routes, no queue event listeners, no Horizon menu link.
    */
    'enabled' => env('HORIZON_OBSERVABILITY_ENABLED', true),

    /*
    | Metric name prefix: "<namespace>_<family>", e.g. ho_queue_jobs_total; self-metrics ho_exporter_*. '' = no prefix.
    */
    'namespace' => env('HORIZON_OBSERVABILITY_NAMESPACE', 'ho'),

    /*
    | Base path of the endpoints. null = "<horizon.path>/observability" (default "/horizon/observability"):
    |   GET {path}/metrics       Prometheus text exposition
    |   GET {path}/jobs/{list}   JSON job lists for Grafana: pending, completed, failed, delayed
    |   GET {path}/live          live snapshot JSON for Grafana (when live.enabled)
    */
    'path' => env('HORIZON_OBSERVABILITY_PATH'),

    /*
    | Host the endpoints answer on. null = any host (Prometheus usually scrapes an internal address).
    | Horizon's own domain is deliberately not inherited.
    */
    'domain' => env('HORIZON_OBSERVABILITY_DOMAIN'),

    /*
    | Access. Bearer tokens (comma-separated for rotation, each >= 16 characters) for both endpoints.
    | api_token: optional separate tokens for the job lists (Grafana); null = same as token.
    | allowed_ips: optional IPs/CIDRs (comma-separated); combined with tokens, both must pass.
    | With none of them (and no HorizonObservability::auth() callback) the endpoints answer only
    | in the "local" environment, like Horizon's default gate; everywhere else they return 403.
    */
    'token' => env('HORIZON_OBSERVABILITY_TOKEN'),
    'api_token' => env('HORIZON_OBSERVABILITY_API_TOKEN'),
    'allowed_ips' => env('HORIZON_OBSERVABILITY_ALLOWED_IPS'),

    /*
    | Extra route middleware, run after authorization, e.g. ['throttle:600,1'].
    */
    'middleware' => [],

    /*
    | Keep the endpoints reachable during "php artisan down".
    */
    'available_during_maintenance' => true,

    /*
    | Exception text in the failed-jobs list: full (short class + message, first line), class, none.
    */
    'exceptions' => env('HORIZON_OBSERVABILITY_EXCEPTIONS', 'full'),

    'redis' => [
        // Connection for the package's own keys. null = Horizon's "horizon" connection (keys carry horizon.prefix).
        'connection' => env('HORIZON_OBSERVABILITY_REDIS_CONNECTION'),
        // Seconds. Private clients: worker-side writes, and scrape/job-list reads.
        'worker_timeout' => 1.0,
        'scrape_timeout' => 2.0,
    ],

    /*
    | Collectors. Disabling one also stops the work behind it ('attempts' stops recording in workers).
    | batches: null = auto (database batching driver and existing table), true = required, false = off.
    */
    'collectors' => [
        'horizon' => true,       // horizon_status, horizon_supervisor_*, horizon_wait_threshold_seconds, horizon_info
        'queues' => true,        // queue_pending_jobs, queue_delayed_*, queue_oldest_pending_job_age_seconds, queue_jobs_pending|reserved
        'attempts' => true,      // queue_jobs_total, queue_job_duration_*, queue_job_wait_seconds
        'job_classes' => true,   // queue_job_classes
        'batches' => null,       // queue_batch*
    ],

    /*
    | Histogram buckets (seconds) for queue_job_duration_seconds and queue_job_wait_seconds.
    | Safe to change: buckets are stored non-cumulatively.
    */
    'buckets' => [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 120, 300, 600, 1800, 3600],

    /*
    | Jobs inspected from the head of each queue per scrape for the per-class breakdown and the oldest age.
    | The rest is reported as job_class="(unscanned)". 0 = head only. Redis is blocked while a queue is scanned.
    */
    'queue_scan_limit' => (int) env('HORIZON_OBSERVABILITY_QUEUE_SCAN_LIMIT', 1000),

    /*
    | Upper bound of distinct job_class values in attempt metrics; further classes become "(other)".
    | Classes idle for 30 days are pruned automatically.
    */
    'max_job_classes' => 500,

    /*
    | Directories scanned for queueable classes (queue_job_classes). null = [app_path()].
    | Cached by "php artisan horizon-observability:cache" (runs with "php artisan optimize").
    */
    'job_class_paths' => null,

    /*
    | Public Horizon URL used for dashboard links. null = app.url + horizon.domain + horizon.path.
    */
    'horizon_url' => env('HORIZON_OBSERVABILITY_HORIZON_URL'),

    'grafana' => [
        // Dashboard URL. When set, Horizon's sidebar gets a "Grafana" link right after "Dashboard".
        'url' => env('HORIZON_OBSERVABILITY_GRAFANA_URL'),
        // Spoken alerts: null = tone only, 'browser' = Web Speech API, or a Piper HTTP server base URL
        // (POST {url}/synthesize, must allow CORS from Grafana's origin).
        'tts_url' => env('HORIZON_OBSERVABILITY_TTS_URL'),
    ],

    /*
    | Live Overview. enabled: GET {path}/live (live snapshot JSON for Grafana) and the recent-attempts ring buffer
    | written by workers. cache_ttl: seconds a snapshot is shared by all viewers and replicas (0 = no cache).
    | stream: settings of "php artisan horizon-observability:stream", which pushes the snapshot to Grafana Live.
    */
    'live' => [
        'enabled' => env('HORIZON_OBSERVABILITY_LIVE_ENABLED', true),
        'cache_ttl' => (float) env('HORIZON_OBSERVABILITY_LIVE_CACHE_TTL', 1.0),
        'stream' => [
            // Grafana base URL reachable from the app; comma-separated for several Grafana instances without Live HA.
            'grafana_url' => env('HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL'),
            // Grafana service account token; role Editor (live:push) on Grafana 13, any role on 12. Never logged.
            'token' => env('HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN'),
            // Grafana Live stream id: channels stream/<id>/tiles and stream/<id>/delayed. Must match the dashboard render.
            'id' => env('HORIZON_OBSERVABILITY_LIVE_STREAM_ID', 'horizon_observability'),
            // Seconds between pushes.
            'interval' => (float) env('HORIZON_OBSERVABILITY_LIVE_STREAM_INTERVAL', 1.0),
        ],
    ],

    /*
    | Log channel for the package's (throttled) warnings. null = default channel.
    */
    'log_channel' => env('HORIZON_OBSERVABILITY_LOG_CHANNEL'),

];
