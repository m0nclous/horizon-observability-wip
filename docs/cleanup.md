# Метрики очередей: что удалить после упрощения

Новый дашборд «Queues» — `resources/grafana/dashboards/queues.json` (uid `horizon`). Он показывает статус Horizon, процессы, ожидающие, выполненные, отложенные и проваленные джобы, таблицу очередей, график ожидающих по задачам и таблицу задач по классам джобов.

Всё, что ему не нужно, удалено 2026-10-16 (отмечено ниже). Осталась временная нагрузка для проверки на QA.

## Остаётся: на этом строится новый дашборд

| Метрика | Источник | Где на дашборде |
|---|---|---|
| `office_horizon_status` | spatie `HorizonStatusCollector` | плитка «Horizon», статус очереди |
| `office_horizon_supervisor_processes{supervisor, queue}`, `office_horizon_supervisor_min_processes{supervisor}`, `office_horizon_supervisor_max_processes{supervisor}`, `office_horizon_supervisor_info{supervisor, balance, connection}` | `HorizonSupervisorsCollector` | таблица очередей по супервизорам (связь очереди с супервизором за сутки) и её заголовок «имя · Processes: [min, max] · Balancing · Connection», колонка «Utilization» и цвет чисел плитки «Supervisor Processes»; «Pending by job»: пороги лимита воркеров и перегрузки (2x) — `maxProcesses` супервизора очереди |
| `office_horizon_current_processes{queue}` | `HorizonWorkloadCollector` | плитка «Supervisor Processes» и колонка «Processes» — работающие процессы: пока Horizon на паузе или остановлен (`office_horizon_status` < 1), 0 |
| `office_queue_pending_jobs{connection, queue}` | `QueueStateCollector` | плитка и колонка «Pending», список очередей |
| `office_queue_oldest_pending_job_age{connection, queue}` | `QueueStateCollector` | статус очереди, колонка «Oldest wait», цвет и подпись плитки Pending |
| `office_queue_job_wait_seconds{connection, queue}` (гистограмма) | `QueueJobsCollector`: время от постановки в очередь (без `delay()`) до первой попытки — `rememberReadyAt()`/`pullReadyAt()` в `QueueJobMetricsStorage`, ключи `prometheus:queue:ready_at:*`, `handleJobQueued()` и `waitSeconds()` в `QueueJobMetricsSubscriber` | колонка «Wait p95» в таблицах очередей |
| `office_queue_jobs_total{connection, queue, job_class, status}` | `QueueJobsCollector` | плитки «Completed» и «Failed», графики «Completed by job» по очередям; в таблицах очередей и задач — «Failed», «Retries», «Timeouts», «Success rate», в таблице очередей ещё «Completed»; в таблице «Batches» — «Completed», «Failed», «RPM»; статус очереди |
| `office_queue_jobs_pending{connection, queue, job_class}` | `QueueStateCollector`, разбор головы очереди Lua-скриптом (`pending_scan_limit`) | таблица задач: «Pending»; графики «Pending by job» по очередям |
| `office_queue_jobs_reserved{connection, queue, job_class}` | `QueueStateCollector`, просмотр `:reserved` Lua-скриптом | таблицы задач и «Batches»: «Workers» |
| `office_queue_job_duration_seconds{connection, queue, job_class}` | `QueueJobsCollector`, `duration_buckets` | таблица задач: «Avg», «p95» |
| `office_queue_job_duration_min_seconds`, `office_queue_job_duration_max_seconds` `{connection, queue, job_class}` | `QueueJobsCollector`, минутные окна в Redis | таблица задач: «Min», «Max» |
| `office_queue_delayed_jobs`, `office_queue_delayed_due_jobs` `{connection, queue}` | `QueueStateCollector` | плитка и колонка «Delayed»: отложенные минус те, у которых срок наступил |
| `office_queue_job_classes{job_class}` | `QueueJobClassesCollector`, классы очередей из кода (`QueuedJobClasses`) | таблица задач: невыполнявшиеся строки с нулями, фильтр «Job» |
| `office_queue_batches_running{batch}`, `office_queue_batch_jobs{batch, state}`, `office_queue_batch_oldest_age_seconds{batch}`, `office_queue_batch_job_counts{batch, name, state}`, `office_queue_batch_created_timestamp_seconds{batch, name}`, `office_queue_batch_closed_timestamp_seconds{batch, name}`, `office_queue_batch_progress{batch, name, status, size, created, id}` | `QueueBatchesCollector`, таблица `job_batches_office` | таблица «Batches» в «Current Workload» — нагрузка по видам: «Running», «Progress», «Pending», «Oldest»; ряд «Details · Batches»: таблица на каждый вид батча за выбранный период (повтор по скрытой `batch_title` — выбранные в фильтре Batch виды, текст — заголовок) — его батчи построчно: статус, прогресс, джобы, создан, длительность; имя — ссылка на батч в Horizon |
| `GET /metrics/horizon/pending-jobs`, `…/completed-jobs`, `…/failed-jobs`, `…/delayed-jobs` (не метрики, JSON) | `HorizonJobsController`, `HorizonJobsService`, `HorizonJobsRepository`: Redis Horizon, `pending_jobs`, `completed_jobs` и `failed_jobs`; `DelayedJobsRepository`: `очередь:delayed` в Redis очередей | таблицы в «Details · Jobs»: «Failed Jobs» на всю ширину, под ней «Completed Jobs», справа от неё «Pending Jobs» и «Delayed Jobs», через источник Infinity (скрытая переменная `app_api`) |
| `office_horizon_wait_threshold_seconds{connection, queue}` | `HorizonWaitsCollector`, `horizon.waits` | цвет и подпись плитки Pending, порог и цвет в колонке «Oldest wait», цвет «Wait p95» в таблицах очередей |
| `office_horizon_info{url, tts_url}` | `HorizonInfoCollector`, конфиг `app.url`, `horizon.domain`, `horizon.path`, `horizon-prometheus-grafana.tts_url` | ссылка «Horizon» рядом с фильтрами (скрытая переменная `horizon_url`), адрес синтеза речи алертов (скрытая переменная `tts_url`) |
| `office_queue_batch_info{batch, queue, connection}` | `QueueBatchesCollector`, те же связи из Redis, что у `office_queue_batch_job_classes` | заголовки таблиц в «Details · Batches» (скрытая переменная `batch_title`) |
| `office_queue_batch_job_classes{batch, queue, job_class}` | `QueueBatchesCollector`, связи из `QueueJobMetricsSubscriber::rememberBatchJobClass()` в Redis (`prometheus:queue:batch_job_classes`) | фильтр Batch сужает списки Queue и Job до очередей и классов выбранных батчей; в таблице «Batches» — «Workers», «Completed», «Failed» и «RPM» по классам джобов вида |

## На удаление

### Метрики и код за ними

- [x] `office_queue_jobs_reserved_max_age_seconds`: наименьший score из `:reserved` в Lua-скрипте и `oldestReservedAt` в `QueueState::$reservedByClass`
- [x] `office_queue_reserved_jobs`: `QueueState::$reserved`
- [x] `office_queue_job_last_attempt_timestamp_seconds`: `lastAttempts()` в `QueueJobMetricsStorage`
- [x] `office_horizon_current_workload` и `office_horizon_queue_wait_time_seconds` в `HorizonWorkloadCollector`. Из коллектора остаются только процессы
- [x] Настройки из `HorizonSupervisorsCollector::SETTINGS`, кроме `minProcesses` и `maxProcesses`: `office_horizon_supervisor_timeout_seconds`, `_max_tries`
- [x] Коллекторы spatie `CurrentMasterSupervisorCollector`, `FailedJobsPerHourCollector`, `JobsPerMinuteCollector`, `RecentJobsCollector` в `PrometheusServiceProvider`

### Дашборды, документация, тестовый код

- [x] `docs/grafana/queue-dashboard.json`: старый дашборд «Очереди Horizon» (uid `mnm4vzx`). В рабочей Grafana его удалить вручную, если был импортирован
- [x] `README.md`: переписать под новый дашборд. Разделы о старых секциях, разборе «кто стопорит» и «что вынести из default» и алерты на удаляемых метриках убрать
- [x] Раздел «Метрики очередей в Prometheus» в `README.md`: оставить только метрики из таблицы выше
- [x] `tests/Fixtures/Jobs/QueueMetrics`: их заменяет команда `queue-metrics:load-test`
- [ ] Временная команда `queue-metrics:load-test` и `SushiMarket\HorizonPrometheusGrafana\Jobs\QueueLoadTestJob`: удалить после проверки на QA
