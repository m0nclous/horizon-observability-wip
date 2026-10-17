# Мониторинг очередей: практики и идеи для дашборда «Queues»

Ресёрч 2026-10-15: статьи, документация, форумы. Отсюда берём идеи для следующих итераций дашборда. Ссылки — в конце. Пометка «по сниппету» значит, что страница не открывалась: вывод сделан по выдаче поиска.

## A. Общие практики

1. **Главный сигнал — возраст самой старой готовой задачи, а не длина очереди.** Длина обманывает: 10 000 быстрых задач разбираются за то же время, что 10 медленных. Большая очередь при малом возрасте — воркеры не успевают. Маленькая очередь при большом возрасте — консьюмер завис. Алерт ставить на возраст, длину держать контекстом. Отложенные задачи в возраст не включать [1][2][3][4].
2. **SLI «задача стартовала за N секунд» для каждой очереди.** В GitLab у задач есть срочность: high — старт до 10 с, low — до минуты [5]. Judoscale называет очереди по SLA, например `within_5_seconds` [1]. Доля за 5 минут: `sum by (queue) (rate(wait_bucket{le="10"}[5m])) / sum by (queue) (rate(wait_count[5m]))`. Граница bucket-а должна совпадать с порогом SLO [6].
3. **Поступление против выполнения (закон Литтла).** Если поступает больше, чем выполняется, бэклог растёт. Время разбора ≈ готовые задачи / недавний темп выполнения [4]. Prometheus для офлайн-обработки советует считать вход, выход, выполняемое сейчас и время последней обработки [7]. Скетч: `pending / clamp_min(rate(completed[15m]), 1e-3)`.
4. **Насыщение (USE).** Утилизация — доля занятых воркеров, насыщение — длина очереди или ожидание [8]. Задержка растёт ещё до 100% загрузки [9]. Скетч: `sum by (queue) (reserved) / sum by (queue) (processes)`.
5. **Ошибки.** Финальные провалы (аналог DLQ) отделять от повторов. Длительность успешных и упавших попыток считать отдельно [9]. На малом трафике доля «прыгает» от одной ошибки: нужен минимальный объём выборки или длинное окно [10].
6. **Детектор тишины.** AWS советует алерт на `NumberOfMessagesSent = 0`: продюсер перестал отправлять. Порог возраста — примерно на 50% выше ожидаемого времени обработки [11]. Для периодических задач ключевая метрика — когда последний раз выполнилось успешно [7].
7. **Гистограммы, а не summary.** Summary не агрегируются между экземплярами. Точность квантиля у классической гистограммы ограничена шириной bucket-а [6]. Нативные гистограммы в PHP-клиенте, похоже, не поддерживаются (не подтверждено).
8. **Кардинальность.** Большинство метрик — без меток. Всё, что может перерасти 100 рядов, разбирать отдельно. Заранее известные ряды инициализировать нулём, чтобы они не появлялись только после первого события [7].
9. **Дашборд** отвечает на «что сломалось» (симптом, по нему алерт) и «почему» (причина, для разбора). Снижает когнитивную нагрузку и ведёт от общего к частному [9][12][13].

## B. Laravel и Horizon

- **Ожидание у Horizon — оценка, а не факт.** `wait = readyNow × среднее время / процессы`; при 0 процессов — просто сумма работы. `LongWaitDetected` проверяется раз в минуту, порог по умолчанию 60 с [14][15]. Реальный возраст задачи надёжнее.
- **Auto-balancing не даёт приоритета между очередями.** `maxProcesses` — общий потолок супервизора, приоритет — только отдельными супервизорами [16]. Это наша проблема с `default`: медленные задачи занимают общий пул. Нужна панель «процессы против maxProcesses по супервизору».
- **timeout против retry_after.** `timeout` должен быть на несколько секунд меньше `retry_after`, иначе задача выполнится дважды [16][17]. При уменьшении процессов в режиме auto Horizon убивает зависших воркеров после timeout [16]. Отсюда «attempted too many times», джоб одновременно completed и failed, failed с опозданием на `retry_after` [18][19].
- **Осиротевшие reserved после OOM или kill** висят до `retry_after` [20]. Признак — reserved старше timeout с запасом.
- **Перезапуски воркеров.** `WorkerStopping` передаёт статус и причину: память (exit 12), таймаут, max jobs, max time, сигнал перезапуска [21]. По умолчанию `maxJobs=0`, `maxTime=0`; утечки памяти кончаются OOM [20][22].
- **Бесконечные release.** `RateLimited`, `WithoutOverlapping` и `release()` тратят попытки [23]. Что даёт Horizon без явного `tries`, источники расходятся: 1 по документации и конфигу [16][22], «бесконечно» по hackernoon [24]. Метрика — release на одно выполнение по классу.
- **Unique-локи.** Неосвободившийся lock молча блокирует новые dispatch [24][25]; советуют считать такие локи [25].
- **Redis.** `noeviction`, отдельный Redis под очереди; метрики used/max memory и evicted keys (должно быть 0) [25][26][27].
- **Деплой.** Нужен `horizon:terminate`, иначе воркеры на старом коде. Следить за паузами (`queue:pause`, `horizon:pause-supervisor`) [16][17]. Экспортёр boring-o11y отдаёт `queue_paused`, `supervisor_paused`, «процессов 0 при непустой очереди» и `time_to_clear` [28].
- **Рост failed_jobs и job_batches** — нужны `queue:prune-failed` и `queue:prune-batches` [17].
- **Готовые инструменты.** Pulse и Nightwatch показывают queued, processing, processed, released, failed, среднее и p95, порог медленных задач по классу [29][30].

## C. Чего не хватает нашему дашборду

**Высокий приоритет**
1. SLI «стартовала за N с» по очереди со своими порогами: для заказов 5–10 с, для отчётов минуты. Bucket-ы `office_queue_job_wait_seconds` выровнять под пороги (1, 5, 10, 30, 60, 300). Сейчас пороги статуса одни на все очереди (1 и 5 мин), для заказов это слишком поздно.
2. Насыщение по супервизору: занятые / процессы и `processes ≥ maxProcesses` рядом с возрастом задачи. Прямо показывает, когда `default` упирается в потолок.
3. Счётчик отправок (событие `JobQueued`) по очереди: вход против выхода, тренд бэклога, время разбора. Сейчас вход не измеряется.
4. Осиротевшие reserved: `reserved_max_age > timeout + запас`. Экспортировать `retry_after` и алертить на `timeout ≥ retry_after`.
5. Перезапуски воркеров по причине (`WorkerStopping`): память, таймаут, max jobs.
6. Redis: evicted keys, used/max memory, политика вытеснения — через redis_exporter.

**Средний приоритет**
7. Циклы release: `rate(released) / rate(completed)` по классу или максимальный номер попытки.
8. Свежесть критичных классов: `time() - время последнего успеха` (сейчас есть только последняя попытка любого итога). Алерт «отправок 0» для очередей с постоянным потоком.
9. Паузы очередей и супервизоров, ожидаемое и фактическое число супервизоров, время старта мастера (зависание после деплоя).
10. Рост failed_jobs как DLQ.
11. Здоровье экспортёра: `up`, длительность сбора, `absent()`.

**Низкий приоритет**
12. Число unique-локов, батчи.

**Что, по мнению ресёрча, шум или стоит понизить**
- `office_horizon_queue_wait_time_seconds` дублирует возраст задачи и лишь оценивает его.
- Минимум длительности — шум; максимум полезен в сравнении с timeout.
- Успешность в % у малообъёмных классов прыгает: лучше абсолютные числа или порог объёма.
- WARN «ошибки за 15 мин» — алерт на причину, а не на симптом. Лучше доля финальных провалов при минимальном объёме.
- Плитка «всего процессов» без сравнения с максимумом мало что говорит.
- `current_workload`, будущие `delayed_jobs` и общий `reserved_jobs` — для детального разбора, не для главного экрана.
- Возраст задачи считать от момента доступности, а не от постановки, иначе отложенные и backoff-задачи дают ложный ERROR. У нас отложенные с наступившим сроком считаются от срока, а головной джоб — от последней постановки (`pushedAt`), что завышает возраст вернувшихся в очередь (см. README.md).

## Источники

1. https://judoscale.com/blog/planning-sidekiq-queues — длина очереди обманывает, очереди по SLA, выделенные процессы
2. https://github.com/sidekiq/sidekiq/wiki/Monitoring — latency вместо размера
3. https://www.deadqueue.com/blog/sqs-age-based-alerting-slow-drain-expiration — матрица возраст/длина (по сниппету)
4. https://engineering.hammedajibade.com/p/queue-depth-hides-the-oldest-promise — время разбора, исключение отложенных
5. https://docs.gitlab.com/development/sidekiq/worker_attributes/ и https://docs.gitlab.com/development/application_slis/sidekiq_execution/ — срочность, apdex
6. https://prometheus.io/docs/practices/histograms/
7. https://prometheus.io/docs/practices/instrumentation/
8. https://www.brendangregg.com/usemethod.html
9. https://sre.google/sre-book/monitoring-distributed-systems/
10. https://sre.google/workbook/alerting-on-slos/
11. https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/Best_Practice_Recommended_Alarms_AWS_Services.html (раздел SQS)
12. https://grafana.com/docs/grafana/latest/visualizations/dashboards/build-dashboards/best-practices/
13. https://prometheus.io/docs/practices/alerting/
14. https://raw.githubusercontent.com/laravel/horizon/5.x/src/WaitTimeCalculator.php
15. https://raw.githubusercontent.com/laravel/horizon/5.x/src/Listeners/MonitorWaitTimes.php
16. https://laravel.com/docs/12.x/horizon
17. https://laravel.com/docs/12.x/queues
18. https://laracasts.com/discuss/channels/laravel/maxattemptsexceededexception-job-has-been-attempted-too-many-times-or-run-too-long-the-job-may-have-previously-timed-out (по сниппету)
19. https://laracasts.com/discuss/replies/431697 (по сниппету)
20. https://hackernoon.com/how-i-cut-vps-memory-usage-from-90percent-to-65percent (по сниппету)
21. https://raw.githubusercontent.com/laravel/framework/12.x/src/Illuminate/Queue/Worker.php
22. https://raw.githubusercontent.com/laravel/horizon/5.x/config/horizon.php
23. https://laravel.com/docs/12.x/queues (раздел о попытках)
24. https://hackernoon.com/a-series-of-unfortunate-jobs-6-gotchas
25. https://dev.to/vincentbean/my-laravel-horizon-preferences-after-5-years-of-using-it-464k
26. https://redis.io/docs/latest/develop/reference/eviction/ (по сниппету)
27. https://github.com/oliver006/redis_exporter (README подтверждает только `redis_memory_max_bytes`)
28. https://packagist.org/packages/boring-o11y/horizon-prometheus-exporter
29. https://laravel.com/docs/12.x/pulse
30. https://nightwatch.laravel.com/docs/jobs (по сниппету)

Не подтверждено: нативные гистограммы в promphp, точные имена метрик redis_exporter кроме `memory_max_bytes`, значение `tries` по умолчанию в Horizon.
