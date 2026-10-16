# Вынос в отдельный репозиторий: связи с office

Разбор 2026-10-16 по коммиту office `2db50c9a8` (Feature/grafana queue refactoring, #4213): где пакет связан с приложением и что мешает подключить его в другой проект. Статус: 🆕 новое — ещё не решено; 🔨 делать; ⏳ отложено; ❌ пропуск; ✅ сделано.
Улучшения дашборда — в [backlog.md](./backlog.md), удаление старого — в [cleanup.md](./cleanup.md).

## Итог

На уровне PHP-классов пакет изолирован: в `src/` нет ни одного `App\…`, а office не импортирует классы пакета. Связь идёт через:

- composer и Docker в office;
- конфиги Laravel, которые пакет читает и в одном месте меняет (`prometheus`, `horizon`, `queue`, `app`);
- обход `app_path()` в поисках джобов;
- префикс метрик `office_`, прописанный в ресурсах Grafana.

## Office → пакет: явные точки

| Где в office | Что | При выносе |
|---|---|---|
| `composer.json` | path-репозиторий `packages/horizon-prometheus-grafana` и `"sushi-market/horizon-prometheus-grafana": "^1.0"` | `{"type": "vcs", "url": "git@github.com:sushi-market/horizon-prometheus-grafana.git"}`, как у `service_messenger`, `request_logger` и `bitrix24`; затем `composer update sushi-market/horizon-prometheus-grafana` |
| `Dockerfile` | `COPY packages ./packages` перед `composer install` | Удалить: других пакетов в `packages/` нет. Доступ к приватному репозиторию уже есть — `composer install` идёт с `--mount=type=ssh` |
| `docker-compose.override.yml`, сервис `grafana` | Монтирует `./packages/horizon-prometheus-grafana/resources/grafana/dashboards` и `…/alerting` | Заменить на `./vendor/sushi-market/horizon-prometheus-grafana/resources/grafana/…` |
| `docker-compose.override.yml`, окружение `grafana` | `GF_PLUGINS_PREINSTALL_SYNC` (Infinity 4.1.1, Business Text 6.3.0), `HPG_PROMETHEUS_DATASOURCE_UID`, домашний дашборд `/etc/grafana/dashboards/queues.json` | Остаётся в office: это окружение, требования описаны в README пакета |
| `.docker/grafana/provisioning/` | Источники Prometheus (uid `prometheus`) и Infinity «App API» (`http://app:8080`), провайдер дашбордов из `/etc/grafana/dashboards` | Остаётся в office |
| `.docker/prometheus/prometheus.yml` | Job `office` собирает `app:8080/metrics` | Остаётся в office |
| `app/Providers/EventServiceProvider.php`, `app/Providers/PrometheusServiceProvider.php` | Упоминаний пакета нет, провайдер подключается через package discovery | Ничего |
| `tests/` | Ссылок на пакет нет: фикстуры нагрузки удалены, нагрузка переехала в `QueueLoadTestJob` пакета | Ничего |

## Пакет → приложение: неявные контракты

Пакет ничего не импортирует из office, но рассчитывает на конфиги и окружение приложения.

| Что читает или меняет пакет | Где в пакете | Зачем |
|---|---|---|
| `prometheus.default_namespace` | неявно: имена метрик; `resources/grafana/dashboards/queues.json`, `resources/grafana/alerting/horizon.yml` | Префикс всех метрик. Дашборд и алерты написаны с `office_` |
| `prometheus.cache` = `null` | нигде не проверяется, только комментарий в `config/horizon-prometheus-grafana.php` и README | Счётчики публикуют абсолютные значения на каждый сбор: с постоянным хранилищем они росли бы с каждым сбором |
| `prometheus.allowed_ips` | `routes.middleware` конфига пакета → `Spatie\Prometheus\Http\Middleware\AllowIps` | Доступ к `/metrics/horizon/*` тот же, что к `/metrics` |
| `horizon.environments`, `horizon.defaults` | `HorizonQueues` через `ProvisioningPlan` | Список очередей, которые поднимет Horizon в текущем окружении |
| `horizon.env`, `app.env` | `HorizonQueues` | Выбор окружения Horizon |
| `horizon.waits` | `Prometheus\HorizonWaitsCollector` | Пороги долгого ожидания |
| `horizon.domain`, `horizon.path`, `app.url` | `Prometheus\HorizonInfoCollector` | Ссылки на Horizon с дашборда |
| `horizon.middleware` — **пакет дописывает** `AddGrafanaLinkToHorizon` | `HorizonPrometheusGrafanaServiceProvider::register()` | Пункт «Grafana» в меню Horizon |
| `queue.batching.database`, `queue.batching.table` (в office — `job_batches_office`) | `Prometheus\QueueBatchesCollector` | Батчи из БД |
| `queue.connections.*.driver`, `queue.connections.*.queue` | `QueueJobMetricsSubscriber` | Учёт попыток только для Redis-очередей, очередь по умолчанию |
| `queue.default` | `Console\QueueLoadTestCommand` | Соединение для нагрузки |
| `app.timezone` | `Prometheus\QueueBatchesCollector` | Подписи времени батчей |
| `app_path()` | `QueuedJobClasses` | Поиск классов с `ShouldQueue` для `queue_job_classes` |
| Redis-соединение `horizon` | `Repositories\HorizonJobsRepository` | Его регистрирует сам Horizon (`Horizon::use()`): это контракт Horizon, а не office |
| Redis-соединение из `HPG_REDIS_CONNECTION` (по умолчанию `default`) | `QueueJobMetricsStorage` | Должно быть в `database.redis` приложения и совпадать в app и horizon |

Глобальные побочные эффекты:

- `Event::subscribe(QueueJobMetricsSubscriber::class)` — на события очередей во всех процессах приложения, не только в воркерах Horizon.
- Коллекторы пакета попадают в общий реестр spatie вместе с коллекторами приложения (в office — `OrderCollector`, `EmployeeAuthMetricsCollector`, `FrontMetricsCollector`): один `/metrics` и один namespace на всех.
- Пакет сам регистрирует `HorizonStatusCollector` из spatie: приложению регистрировать его не нужно.

## Внутренности Horizon, на которые опирается пакет

Проверять при обновлении Horizon:

- `AddGrafanaLinkToHorizon` ищет в HTML Horizon `class="col-2 sidebar"` и первый `</li>`. Сменится разметка — пункт «Grafana» молча пропадёт.
- `HorizonJobsRepository` читает Redis-структуры Horizon напрямую: sorted set-ы `pending_jobs`, `completed_jobs`, `failed_jobs` и hash-и джобов.
- `HorizonQueues` повторяет логику `ProvisioningPlan::deploy()`: выбор окружения по `Str::is`, пропуск супервизоров с `maxProcesses <= 0`.

## Что мешает переиспользованию

| № | Что | Зачем | Статус |
|---|-----|-------|--------|
| 1 | Префикс `office_` в `resources/grafana/alerting/horizon.yml` прописан руками, генератора с `--prefix` для алертов нет | В проекте с другим `prometheus.default_namespace` алерты молча ничего не найдут | 🆕 новое |
| 2 | Тег `office` в дашборде: генератор пишет `"tags": ["horizon", "queue", "office"]` при любом `--prefix` | В другом проекте дашборд будет помечен как office | 🆕 новое |
| 3 | `QueuedJobClasses` обходит только `app_path()` | Джобы из других пакетов и модулей вне `app/` не попадут в `queue_job_classes` и таблицу задач. Нужен список путей в конфиге | 🆕 новое |
| 4 | `prometheus.cache` = `null` нигде не проверяется | С кэшем в spatie счётчики будут молча расти на каждом сборе. Проверять в `boot()` и писать в лог или падать | 🆕 новое |
| 5 | `"version": "1.0.0"` в `composer.json` пакета | Для path-репозитория поле нужно, для vcs версия берётся из git-тегов и поле будет им противоречить | 🆕 новое |
| 6 | В пакете нет тестов | В office их тоже нет; отдельному репозиторию нужен свой стенд (orchestra/testbench, Redis) | 🆕 новое |
| 7 | Пакет требует `spatie/laravel-prometheus` `^1.3`, office — `^1.2` | Пакет фактически поднимает минимальную версию в приложении. Не мешает, но стоит знать | 🆕 новое |
| 8 | Примеры в документации из office: `job_name: office`, `scrape_duration_seconds{job="office"}`, `job_batches_office`, `office_…` в [cleanup.md](./cleanup.md) | Для чужого проекта читаются как обязательные имена | 🆕 новое |

## Как вынести

1. Сохранить историю: `git subtree split --prefix=packages/horizon-prometheus-grafana -b horizon-prometheus-grafana` в office и запушить ветку в новый репозиторий. История до #4213 жила в `app/Services/QueueMetrics` и в split не попадёт — сослаться на #4190 и #4213 в README.
2. В пакете убрать `"version"` из `composer.json`, поставить тег `v1.0.0`.
3. В office заменить path-репозиторий на vcs, выполнить `composer update sushi-market/horizon-prometheus-grafana`, удалить `packages/horizon-prometheus-grafana`.
4. В office убрать `COPY packages ./packages` из `Dockerfile`.
5. В office перевести монтирование Grafana в `docker-compose.override.yml` на `./vendor/sushi-market/horizon-prometheus-grafana/resources/grafana/…`.
6. В README пакета обновить раздел «Установка»: path-репозиторий больше не основной вариант.
