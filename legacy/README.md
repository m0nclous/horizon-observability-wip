# sushi-market/horizon-prometheus-grafana

> Метрики Horizon и очередей для Prometheus, дашборд Grafana «Queues» (`resources/grafana/dashboards/queues.json`, uid `horizon`) с алертами, звуком и речью. Как подключить в проект и в проде и как читать дашборд.

## TL;DR

- Метрики отдаёт `/metrics` приложения, у всех префикс `office_`. Списки джобов для таблиц дашборда — `GET /metrics/horizon/{pending,completed,failed,delayed}-jobs` (JSON) с тем же доступом.
- Prometheus должен собирать метрики очередей **с одного** экземпляра app: все значения берутся из общего Redis. Кому открыт `/metrics`, задаёт `PROMETHEUS_ALLOWED_IPS`, а снаружи весь префикс `/metrics` закрывается в nginx или ingress.
- На дашборде Overview — состояние сейчас: плитки краснеют по тем же порогам, по которым горят алерты. Current Workload — очереди по супервизорам и батчи. Details — задачи, списки джобов как в Horizon и батчи по видам.
- Алерты — правила Grafana в папке Horizon. Дашборд сам звучит, когда они срабатывают: сигнал тревоги и голос с названиями сработавших правил.

## Содержание

- [Установка](#установка)
- [Настройки](#настройки)
- [Подключение в проде](#подключение-в-проде)
- [Метрики](#метрики)
- [Списки джобов](#списки-джобов)
- [Дашборд «Queues»](#дашборд-queues)
- [Алерты и звук](#алерты-и-звук)
- [Генератор дашборда](#генератор-дашборда)
- [Нагрузка тестовыми задачами](#нагрузка-тестовыми-задачами)
- [Как вынести джоб в отдельную очередь](#как-вынести-джоб-в-отдельную-очередь)

## Установка

Пакет лежит в проекте как path-репозиторий composer. После выноса в отдельный git-репозиторий меняется только `repositories`: `{"type": "vcs", "url": "git@github.com:sushi-market/horizon-prometheus-grafana.git"}`.

```json
"repositories": [
    { "type": "path", "url": "packages/horizon-prometheus-grafana" }
],
"require": {
    "sushi-market/horizon-prometheus-grafana": "^1.0"
}
```

```bash
composer update sushi-market/horizon-prometheus-grafana
```

- Сервис-провайдер подключается сам (package discovery): регистрирует коллекторы на `/metrics`, подписчик попыток, маршруты списков джобов, пункт «Grafana» в меню Horizon и команду нагрузки.
- Нужны Laravel 12, Horizon 5, `spatie/laravel-prometheus` 1.3 и `spatie/laravel-data` 4. В `config/prometheus.php` — `'cache' => null`: счётчики публикуют абсолютные значения на каждый сбор и с постоянным хранилищем росли бы с каждым сбором.
- Префикс метрик — `prometheus.default_namespace` приложения. Дашборд и правила алертов написаны с `office_`: для другого префикса дашборд собирается [генератором](#генератор-дашборда) с `--prefix`, а в `resources/grafana/alerting/horizon.yml` префикс меняется вручную.
- Если Docker-образ ставит зависимости отдельным этапом из одних `composer.json` и `composer.lock`, папку пакета нужно скопировать туда до `composer install`.
- Свой конфиг: `php artisan vendor:publish --tag=horizon-prometheus-grafana-config`.

## Настройки

`config/horizon-prometheus-grafana.php`, переменные окружения — с префиксом `HPG_` (horizon-prometheus-grafana):

| Переменная | По умолчанию | Что задаёт |
|---|---|---|
| `HPG_ENABLED` | `true` | Запись попыток воркерами и их публикацию на `/metrics`; значение должно совпадать в app и horizon |
| `HPG_REDIS_CONNECTION` | `default` | Redis для счётчиков попыток, общий для app и horizon |
| `HPG_PENDING_SCAN_LIMIT` | `1000` | Сколько джобов из головы каждой очереди разбирать по классам; `0` — не разбирать (см. [Стоимость разбора](#стоимость-разбора-очереди-по-классам)) |
| `HPG_GRAFANA_URL` | — | Адрес дашборда «Queues» в Grafana этого окружения, например `https://grafana.example.com/d/horizon/queues`: по нему в меню Horizon после Dashboard появляется пункт «Grafana». Без адреса пункта нет |
| `HPG_TTS_URL` | — | Адрес синтеза речи Piper: дашборд произносит по нему сработавшие алерты (см. [Алерты и звук](#алерты-и-звук)). Без адреса — только сигнал |

Там же `duration_buckets` — границы гистограмм и `routes` — префикс (`metrics/horizon`) и middleware (`AllowIps` из spatie, как у `/metrics`) списков джобов. Пороги ожидания очередей — `horizon.waits` в `config/horizon.php` приложения: по ним красится дашборд и горит алерт ожидания.

## Подключение в проде

### Prometheus

```yaml
scrape_configs:
    - job_name: office
      metrics_path: /metrics
      scrape_interval: 15s
      scrape_timeout: 10s
      static_configs:
          - targets: [ '<один экземпляр app>:8080' ]
```

- **Одна цель.** Метрики `office_queue_*` и `office_horizon_*` читаются из общего Redis, и каждый экземпляр app отдаёт одни и те же значения. Если Prometheus собирает N экземпляров, суммы на дашборде умножатся на N. Если собирать несколько экземпляров всё же нужно, на всех, кроме одного, отбросьте эти метрики:
  ```yaml
  metric_relabel_configs:
      - source_labels: [ __name__ ]
        regex: 'office_(queue|horizon)_.*'
        action: drop
  ```
  Это убирает только двойной счёт: ряды отбрасываются уже после сбора, и каждый экземпляр всё равно читает очереди в Redis. Поставьте на этих экземплярах ещё `HPG_PENDING_SCAN_LIMIT=0`: останутся только дешёвые команды (см. [Стоимость разбора](#стоимость-разбора-очереди-по-классам)).
- **`scrape_interval` — не больше 15 с.** Колонка Utilization считает занятые воркеры за 15 с (`BUSY_WINDOW` в генераторе дашборда): при более редком сборе окно окажется пустым, и его нужно расширить.
- **`scrape_timeout`.** Метрики собираются в момент запроса: Lua-скрипт на каждую очередь и чтение данных Horizon. Локально `/metrics` отвечает в среднем за 0,5 с, максимум за 3 с; при длинных очередях дольше. Поставьте 10 с и проверяйте `scrape_duration_seconds{job="office"}`.
- **Версия Prometheus — 2.33 или новее.** Дашборд использует модификатор `@`. Проверить версию: Status → Runtime & Build Information или `curl -s http://<prometheus>:9090/api/v1/status/buildinfo`.
- **`--web.enable-remote-write-receiver`.** Через remote write Grafana пишет состояние своих алертов рядами `GRAFANA_ALERTS`: по ним считает и красится плитка Alerts и звучит дашборд (см. [Алерты и звук](#алерты-и-звук)).

### Grafana

- **Дашборд.** Dashboards → New → Import → `resources/grafana/dashboards/queues.json`. Источник Prometheus он берёт из скрытой переменной `datasource` — источник Prometheus по умолчанию в Grafana, поэтому uid может быть любым. В настройках источника (Interval behaviour → Scrape interval) должен стоять реальный `scrape_interval`, от него считается `$__rate_interval`.
- **Плагины.** Infinity (`yesoreyeram-infinity-datasource`, 4.1.1) — для таблиц Completed, Failed, Pending и Delayed Jobs; Business Text (`marcusolsson-dynamictext-panel`, 6.3.0) — для звука алертов.
- **Источник Infinity** с Base URL и Allowed hosts — адрес приложения. Дашборд находит его по типу (скрытая переменная `app_api`), поэтому имя и uid могут быть любыми.
- **Алерты.** Правила — `resources/grafana/alerting/horizon.yml`, папка Horizon: файл провижининга или импорт через Alerting. У правил нет источника по умолчанию: uid источника Prometheus они берут из переменной окружения Grafana `HPG_PROMETHEUS_DATASOURCE_UID` (Grafana подставляет переменные окружения в файлы провижининга). При импорте через Alerting впишите uid в правила вручную. uid источника — в его настройках, в адресе страницы: `/connections/datasources/edit/<uid>`.
- **История состояний алертов в Prometheus:**
  ```ini
  [unified_alerting.state_history]
  enabled = true
  backend = multiple
  primary = annotations
  secondaries = prometheus
  prometheus_target_datasource_uid = <uid источника Prometheus>
  ```
  У источника Prometheus — тип Prometheus (`prometheusType`). Основная история остаётся в аннотациях: из неё работает вкладка History у правила. Без этих настроек плитка Alerts всегда показывает 0, а дашборд молчит.
- **Приложение.** `HPG_GRAFANA_URL` — адрес дашборда для пункта «Grafana» в меню Horizon. `HPG_TTS_URL` — адрес синтеза речи, без него дашборд звучит без голоса.
- **Телевизор.** Браузер даёт звук только после клика по странице: Chrome запускать с `--autoplay-policy=no-user-gesture-required`.
- **Правки дашборда.** JSON генерирует скрипт. Правки из UI сохранять так: Export → Export as JSON, формат Classic, переключатель «Export the dashboard to use in another instance» выключен: с ним источник данных станет `${DS_PROMETHEUS}`, и файл перестанет загружаться. Файл дашборда из провижининга Grafana перечитывает сама, а правила алертов — после перезапуска или `POST /api/admin/provisioning/alerting/reload`. Воркеры Horizon подхватывают правки PHP-кода пакета только после перезапуска Horizon.

### Доступ к /metrics

Пока `PROMETHEUS_ALLOWED_IPS` пуст, `/metrics` и списки джобов `/metrics/horizon/*` открыты всем. Каждый запрос запускает Lua-скрипты в Redis и показывает имена классов джобов, а списки — ещё и сообщения исключений. Впишите в `PROMETHEUS_ALLOWED_IPS` адреса или подсети CIDR через запятую, например `10.0.0.5,10.201.0.0/16`.

Проверяется `$request->ip()`. Если соединение пришло от доверенного прокси (`TRUSTED_PROXIES`, по умолчанию все частные сети из `config/trustedproxy.php`), адрес берётся из `X-Forwarded-For`. Отсюда два следствия:

- Указывайте адрес Prometheus и Grafana таким, каким его видит приложение: при сборе через ingress это адрес из `X-Forwarded-For`, а не адрес ingress.
- Любой клиент из частной сети может подставить разрешённый адрес в `X-Forwarded-For`. Поэтому одного `PROMETHEUS_ALLOWED_IPS` мало: закройте весь префикс `/metrics` снаружи в nginx или ingress либо задайте в проде `TRUSTED_PROXIES` точными адресами прокси.

### Ключи в Redis

Команды `redis-cli` ниже используют `<REDIS_PREFIX>` — префикс ключей Laravel, `config('database.redis.options.prefix')`. Если `REDIS_PREFIX` не задан, это `slug(APP_NAME)_database_`. Узнать значение на сервере:

```bash
php artisan tinker --execute='echo config("database.redis.options.prefix"), PHP_EOL;'
```

- Очереди — в базе `REDIS_QUEUE_DB` (по умолчанию 3): список `<REDIS_PREFIX>queues:<очередь>`, рядом `:delayed` (отложенные), `:reserved` (взятые воркерами) и служебный `:notify`.
- Метрики попыток — в базе соединения `HPG_REDIS_CONNECTION` (`default` — база `REDIS_DB`, по умолчанию 0), ключи `<REDIS_PREFIX>prometheus:queue:*`.

Подставляйте префикс целиком и не начинайте шаблон с `*`: в общей базе Redis `*prometheus:queue:*` заденет ключи других приложений.

### Стоимость разбора очереди по классам

`office_queue_jobs_pending` считает Lua-скрипт: при каждом сборе он читает из головы очереди до `pending_scan_limit` джобов (`HPG_PENDING_SCAN_LIMIT`, по умолчанию 1000). Redis однопоточный: пока скрипт работает, Horizon не забирает джобы, а кэш и сессии ждут.

Время блокировки на одну очередь за один сбор (замер на тестовом Redis 7.2):

| Размер джоба в Redis | 1000 джобов | 5000 джобов |
|---|---|---|
| 1 КБ | ~3 мс | ~15 мс |
| 5 КБ | ~10 мс | ~40 мс |
| 20 КБ | ~40 мс | ~210 мс |

- Время умножается на число длинных очередей и на число сборов `/metrics`: серверы Prometheus × собираемые экземпляры app. Пустая очередь почти ничего не стоит.
- Перед тем как поднимать лимит, посмотрите в базе очередей `LLEN '<REDIS_PREFIX>queues:<очередь>'` и `MEMORY USAGE '<REDIS_PREFIX>queues:<очередь>'` (см. [Ключи в Redis](#ключи-в-redis)); размер джоба ≈ `MEMORY USAGE` ÷ `LLEN`.
- `0` выключает только разбор по классам; остальные метрики остаются.

### Выкладка

- Метрики попыток пишут процессы Horizon (`QueueJobMetricsSubscriber`). Они появятся после того, как Horizon перезапустится с новым кодом (`php artisan horizon:terminate` при выкладке).
- `HPG_ENABLED` включает и запись попыток, и их публикацию на `/metrics`, поэтому значение должно совпадать в app и horizon. С `false` в app с `/metrics` пропадут `office_queue_jobs_total`, гистограммы и длительности, и большая часть дашборда опустеет. С `false` в процессе, который ставит отложенные джобы, их задержка попадёт в ожидание.
- Гистограмма ожидания начинает копиться с момента выкладки; истории до неё нет. Отложенные джобы, поставленные до выкладки (самая долгая задержка в коде — 25 ч, `DeleteClientReportFileJob`), попадут в гистограмму ожидания вместе со своей задержкой.
- Первый сбор `/metrics` после выкладки один раз обнулит счётчики попыток: `run_id` Redis ещё не сохранён (см. [Где хранятся счётчики](#где-хранятся-счётчики-и-как-их-сбросить)).

## Метрики

Общие оговорки:
- `queue` — имя очереди без соединения: `default`, а не `redis:default`.
- У супервизора с `balance => false` Horizon считает все его очереди одной. В `office_horizon_current_processes` и `office_horizon_supervisor_processes` метка будет `queue="a,b,c"`, и такие ряды не сопоставляются по `queue` с `office_queue_*`. Сейчас у всех супервизоров `balance => auto`.
- «Только ненулевые»: когда значение 0, ряда нет. На графике это разрыв, а не ноль.
- `job_class` — имя класса джоба. У слушателей событий это класс слушателя, у почты — класс mailable.

### Horizon

| Метрика | Метки | Что показывает | Оговорки |
|---|---|---|---|
| `office_horizon_status` | — | 1 — работает, 0 — на паузе, −1 — не запущен ни один мастер | |
| `office_horizon_current_processes` | `queue` | Сколько процессов Horizon сейчас держит на очереди | Сумма по всем супервизорам и серверам. На паузе процессы живы, поэтому дашборд показывает работающими 0, пока `office_horizon_status` < 1 |
| `office_horizon_wait_threshold_seconds` | `connection`, `queue` | Порог ожидания очереди из `horizon.waits` | Без своего — 60 с, как у Horizon; очередь с 0 не отдаётся. По нему красятся плитка Pending, Oldest wait и Wait p95 и горит алерт ожидания |
| `office_horizon_info` | `url`, `tts_url` | 1; адрес Horizon (из `APP_URL`, `HORIZON_DOMAIN`, `HORIZON_PATH`) и синтеза речи (`HPG_TTS_URL`) | По ним ссылка «Horizon» и голос на дашборде ведут в сервисы своего окружения |

### Супервизоры Horizon

Значения берутся из работающего Horizon, а не из `config/horizon.php`. Метка `supervisor` — имя без хоста (`supervisor-default`). Если мастеров несколько, процессы складываются, а настройки берутся по максимуму.

| Метрика | Метки | Что показывает | Оговорки |
|---|---|---|---|
| `office_horizon_supervisor_processes` | `supervisor`, `queue` | Сколько процессов супервизор держит на очереди | |
| `office_horizon_supervisor_min_processes` | `supervisor` | `minProcesses` | При `balance => auto` это минимум **на каждую** очередь |
| `office_horizon_supervisor_max_processes` | `supervisor` | Фактический `maxProcesses` | Horizon поднимает его до числа очередей супервизора |
| `office_horizon_supervisor_info` | `supervisor`, `balance`, `connection` | 1; режим балансировки (`auto`, `simple`, `off`) и соединение | |

### Состояние очередей

Читается из Redis при каждом сборе для всех очередей супервизоров из `config/horizon.php` (так же, как их выбирает Horizon). Очередь, которой там нет, никто не обрабатывает, и в метриках её нет. Ряды без пометки «только ненулевые» есть у каждой очереди, даже пустой.

| Метрика | Метки | Что показывает | Оговорки |
|---|---|---|---|
| `office_queue_pending_jobs` | `connection`, `queue` | Сколько джобов готовы к выполнению и ждут воркера | Длина списка в Redis плюс отложенные с наступившим сроком (`office_queue_delayed_due_jobs`). Отложенные до срока не входят |
| `office_queue_jobs_pending` | `connection`, `queue`, `job_class` | Сколько джобов каждого класса ждёт воркера | Только ненулевые. Разбираются первые `pending_scan_limit` джобов, остаток — `job_class="(не просмотрено)"`; джоб без имени класса — `(неизвестно)` |
| `office_queue_jobs_reserved` | `connection`, `queue`, `job_class` | Сколько джобов класса сейчас взято воркерами | Только ненулевые. Включает «сиротские» резервации: воркер убит (OOM, деплой, таймаут), а джоб числится взятым до `retry_after` и потом вернётся в очередь |
| `office_queue_delayed_jobs` | `connection`, `queue` | Отложенные: `delay()` и возвраты в очередь | Включая отложенные с наступившим сроком |
| `office_queue_delayed_due_jobs` | `connection`, `queue` | Срок наступил, но ни один воркер очереди джоб ещё не забрал | Laravel переносит такие джобы в очередь, только когда её воркер берёт следующий джоб. Пока все воркеры заняты, готовые повторы, возвраты и отложенные джобы копятся здесь, а не в `office_queue_jobs_pending` |
| `office_queue_oldest_pending_job_age` | `connection`, `queue` | Сколько секунд ждёт самый старый готовый джоб: головной в очереди или отложенный с наступившим сроком | 0, если таких нет. Отложенный считается от срока — точно. Головной — от последней постановки в очередь (`pushedAt`), поэтому у вернувшегося в очередь или отложенного при отправке завышено |

### Попытки выполнения

Пишут воркеры при завершении каждой попытки. Есть на `/metrics`, только если `HPG_ENABLED=true` и в app, и в horizon (см. [Выкладка](#выкладка)).

| Метрика | Метки | Что показывает | Оговорки |
|---|---|---|---|
| `office_queue_jobs_total` | `connection`, `queue`, `job_class`, `status` | Счётчик попыток по итогу | Статусы ниже. У класса, который уже выполнялся в очереди, есть ряды всех статусов, недостающие — с нулём. Самая первая попытка нового класса в очереди в `increase()` и `rate()` не видна: ряд появляется сразу с 1. Попытки, убитые без события (OOM, SIGKILL, деплой), не считаются |
| `office_queue_job_duration_seconds` | `connection`, `queue`, `job_class` | Гистограмма длительности попытки | Записывается при завершении: долгий джоб не виден, пока не закончится (смотрите `office_queue_jobs_reserved`) |
| `office_queue_job_wait_seconds` | `connection`, `queue` | Гистограмма: сколько первая попытка ждала свободного воркера с момента, когда джоб стал доступен | Только первые попытки. Задержка `delay()` не входит: срок отложенного джоба запоминается при отправке в ключе `prometheus:queue:ready_at:<id>`. Без `job_class`: в очереди FIFO ожидание задаёт занятость очереди, а не класс |
| `office_queue_job_duration_min_seconds`, `office_queue_job_duration_max_seconds` | `connection`, `queue`, `job_class` | Самая короткая и самая долгая попытка класса за текущую и прошлую минуту | Ряд есть, пока класс выполнялся в последние 2 минуты. За период — `min_over_time`/`max_over_time`. Сбор должен идти не реже раза в минуту |
| `office_queue_job_classes` | `job_class` | 1 для каждого класса из `app/`, который выполняется в очереди | Есть и у классов, которые ни разу не выполнялись. Без `queue`: очередь часто задаётся только при отправке |

Статусы `office_queue_jobs_total`:
- `completed` — завершился без исключения;
- `released` — вернулся в очередь без исключения (`release()`, middleware);
- `retried` — упал с исключением и вернулся в очередь на следующую попытку;
- `failed` — помечен проваленным, в том числе когда попытки кончились ещё до запуска;
- `timed_out` — убит по таймауту.

Границы гистограмм (`duration_buckets`, общие для длительности и ожидания): 0,05; 0,1; 0,25; 0,5; 1; 2,5; 5; 10; 30; 60; 120; 300; 600; 1800; 3600 с.

### Батчи

Батчи (`Bus::batch`, таблица `queue.batching.table`). Метка `{batch}` — вид батча: имя без чисел и uuid (`campaign_launch_15_2026-10-15` → `campaign_launch`), без имени — `unnamed`; `{name}` — имя батча (без имени — id). Выполняющиеся по видам: `office_queue_batches_running`, их джобы `office_queue_batch_jobs{state="total|pending|failed"}` (у Laravel `pending` включает упавшие), `office_queue_batch_oldest_age_seconds`. Отдельные батчи `{batch, name}` — пока выполняются и сутки после закрытия, не больше 200 последних на вид: `office_queue_batch_job_counts{state="total|pending|failed"}`, `office_queue_batch_created_timestamp_seconds`, `office_queue_batch_closed_timestamp_seconds` (у выполняющегося ряда нет), `office_queue_batch_progress{status, size, created, id}` — процент обработанных (`total − pending`, как у Horizon), статус `Running|Finished|Failures|Cancelled`, размер, время создания (UTC) и id батча для ссылки в Horizon. Из них дашборд считает итоги за выбранный период по истории Prometheus. `office_queue_batch_job_classes{batch, queue, job_class}` = 1 — из каких классов и очередей состоят батчи вида: связь пишет подписчик метрик, когда джоб батча ставится в очередь, и забывает через неделю; по ней фильтр Batch сужает очереди и задачи. `office_queue_batch_info{batch, queue, connection}` = 1 — очереди и соединения джобов батчей вида из тех же связей, через запятую, если их несколько: из них заголовки таблиц в «Details · Batches» («вид · Queue: slow · Connection: redis»). Батч с `allowFailures`, у которого остались только упавшие джобы, считается закрытым с ошибками: `finished_at` Laravel ему не ставит

### Где хранятся счётчики и как их сбросить

Счётчики попыток лежат в Redis-соединении `queue_metrics.redis_connection` (`default`, база `REDIS_DB`) в ключах `<REDIS_PREFIX>prometheus:queue:*` (см. [Ключи в Redis](#ключи-в-redis)). TTL у счётчиков нет: классы и очереди, удалённые из кода, остаются в метриках ровными линиями.

После рестарта Redis (сменился `run_id`) первый сбор `/metrics` сам удаляет счётчики попыток и гистограммы. Иначе Redis, поднятый после падения со старым снимком RDB, откатил бы счётчики назад, Prometheus принял бы откат за сброс, и `increase()` посчитал бы весь накопленный итог. Теряются только попытки между рестартом и первым сбором; в лог app пишется «сменился run_id Redis».

Сброс вручную (после удаления очередей или переименования классов):

```bash
# -n 0 — номер базы REDIS_DB
redis-cli -n 0 --scan --pattern '<REDIS_PREFIX>prometheus:queue:*' | xargs -r redis-cli -n 0 del
```

`rate()` и `increase()` в Prometheus переживают сброс счётчиков. У джобов, отложенных до сброса, в ожидание попадёт их задержка (удалятся и ключи `ready_at`).

## Списки джобов

Таблицы «⚠ Failed Jobs» (на всю ширину), «✓ Completed Jobs» и «◷ Pending Jobs» (под ней) в ряду «Details · Jobs» — упавшие, выполненные и ожидающие джобы построчно, как одноимённые страницы Horizon. Выполненные — те же данные из Redis Horizon (`completed_jobs` и hash джоба), но без payload: завершённые за период дашборда, но не раньше `trim.completed` минут назад — дольше Horizon их не хранит. Их отдаёт `GET /metrics/horizon/completed-jobs?limit=500&from=…&to=…` (`from` и `to` — время завершения в миллисекундах, как `${__from}` и `${__to}` в Grafana; новые по времени завершения сверху, как у Horizon). Фильтры — переменные дашборда в формате Grafana `queryparam`: `var-supervisor`, `var-batch_kind`, `var-queue`, `var-job_class`, каждая повторяется на каждое выбранное значение; `$__all` или отсутствие параметра — без фильтра. Супервизор и вид батча приложение само переводит в очереди и классы. Доступ тот же, что у `/metrics` (`routes.middleware` конфига, по умолчанию `AllowIps`), поэтому в nginx/ingress закрывать нужно весь префикс `/metrics`, а не только сам путь. С фильтрами просматриваются последние 10 000 джобов периода. Grafana читает эндпоинт плагином Infinity (`yesoreyeram-infinity-datasource`): в рабочей Grafana нужны плагин и источник Infinity, у которого Base URL и Allowed hosts — адрес приложения. Дашборд находит источник по типу (скрытая переменная `app_api`), поэтому uid может быть любым.

Ожидающие — `GET /metrics/horizon/pending-jobs` с теми же параметрами и доступом, данные — `pending_jobs` в Redis Horizon: поставленные в очередь за период (не раньше `trim.pending` минут назад) и ещё не выполненные. В них и уже взятые воркером: Horizon держит джоб в ожидающих до завершения. Колонки: Job, Queue, Queued.

Упавшие — `GET /metrics/horizon/failed-jobs` с теми же параметрами и доступом, данные — `failed_jobs` в Redis Horizon за период, но не раньше `trim.failed` минут назад (неделя). Колонки: Job, Queue, Exception — класс без пространства имён и сообщение исключения, без места выброса («QueryException: SQLSTATE…»; из Redis читается только первая строка исключения, и только у джобов в ответе), Attempts — сколько раз джоб брал воркер (из payload), Runtime, Failed — время падения. Все три эндпоинта отдают `horizon_path` — страницу джоба в Horizon (`jobs/pending/<id>`, `jobs/completed/<id>` или `failed/<id>`): по нему имя джоба — ссылка в Horizon, у упавших там же кнопка Retry

Отложенные — `GET /metrics/horizon/delayed-jobs` с теми же фильтрами и доступом, таблица «⧖ Delayed Jobs» под Pending Jobs. Это не список Horizon (в его ожидающих отложенный есть только `trim.pending` минут с постановки), а состояние очередей сейчас: sorted set `очередь:delayed` в Redis каждой очереди из `config/horizon.php`, только джобы, срок которых ещё не наступил, — как на плитке Delayed; период дашборда не применяется. Колонки: Job, Queue, Attempts — сколько попыток уже было (больше нуля — повтор, который ждёт паузы), Queued — когда поставлен (`pushedAt` от Horizon), Available — когда станет доступен воркеру; ближайшие сверху

## Дашборд «Queues»

Фильтры Supervisor, Batch, Queue и Job сужают все панели; Reset сбрасывает их разом, Horizon открывает Horizon этого окружения. Состояние «сейчас» — на конец выбранного периода.

### Overview

| Плитка | Число | Цвет |
|---|---|---|
| Horizon | Аптайм за период | Зелёный — работает; жёлтый — работает, но аптайм ниже 99%; красный — на паузе или не запущен |
| Supervisor Processes | Работающие процессы по супервизорам, в подписи — `maxProcesses` | По загруженности пула, как полоса Utilization: до 70% — зелёный, до 90% — жёлтый, выше или Horizon не работает — красный |
| Pending | Готовые к выполнению джобы | По худшей очереди: возраст её старейшего ожидающего выше порога `horizon.waits` — жёлтый (как `LongWaitDetected`), выше двух — красный. Подпись — эта очередь и возраст |
| Completed | Выполненные за период, в подписи — RPM за 5 минут | — |
| Delayed | Отложенные, срок которых ещё не наступил | — |
| Failed | Упавшие и таймауты за период, в подписи — доля успешных | От 1% попыток (и не меньше 1) — жёлтый, от 5% (и не меньше 5) — красный |
| Alerts | Правила дашборда не в норме | Сработавшие — красный; копящие время или 15 минут после последнего сработавшего — жёлтый, в подписи «Stable in 12m» |

### Current Workload

- **Таблицы очередей по супервизорам**, в заголовке — процессы, балансировка и соединение. Utilization — занятые воркеры пула за 15 с на его `maxProcesses`; Processes — работающие процессы; Pending, Delayed; Oldest wait — «возраст старейшего / порог» с цветом как у плитки Pending; Wait p95 — p95 ожидания воркера за период; Completed, Failed, Timeouts, Retries, Success rate.
- **Batches** — нагрузка по видам батчей: выполняющиеся, прогресс, ожидающие, упавшие, воркеры, возраст старейшего, выполненные и RPM за период.

### Details · Jobs

- **Pending by job** и **Completed by job** — графики по задачам для каждой очереди; на Pending — линии лимита воркеров и перегрузки.
- **Jobs** — задачи (классы джобов) за период: ожидающие, упавшие, повторы, Success rate, воркеры, длительность Min/Avg/p95/Max, таймауты.
- **Failed Jobs** — упавшие джобы Horizon с исключением (класс и сообщение, без места выброса); **Completed Jobs** и **Pending Jobs** — как одноимённые страницы Horizon, имя джоба — ссылка в Horizon; **Delayed Jobs** — отложенные, срок которых не наступил, ближайшие сверху: это состояние очередей сейчас, период не применяется.

### Details · Batches

Таблица на каждый вид батча из фильтра Batch: его батчи за период построчно — статус, прогресс, джобы, создан, длительность; имя — ссылка на батч в Horizon.

## Алерты и звук

Правила — `resources/grafana/alerting/horizon.yml`, папка Horizon, проверка раз в минуту. Горят по тем же правилам, по которым краснеют плитки:

| Правило | Условие | Держится |
|---|---|---|
| No queue metrics | Нет ряда `office_horizon_status`: Prometheus не собирает `/metrics` | 5 мин |
| Horizon is down | `min(office_horizon_status) < 1` | 5 мин |
| Queue wait over 2x limit | Старейший ожидающий джоб очереди ждёт дольше двух её порогов `horizon.waits`; по алерту на очередь | 5 мин |
| High failure rate | Упавшие и таймауты в очереди за 15 минут — от 5% попыток, и не меньше 5 | 5 мин |

Точки доставки нет: будит сам дашборд. Кнопка Alert rules (колокольчик) на панели инструментов дашборда показывает его правила.

Звук — JS скрытой панели Business Text над Overview (панель прячется и поднимает сетку на свою строку). Когда сработавших правил становится больше и раз в 5 минут, пока горят, — сигнал тревоги высокого приоритета по мотивам IEC 60601-1-8 (его синтезирует браузер), затем голос с названиями сработавших правил: «Horizon is down. Queue wait over twice the limit.». Голос синтезирует Piper (https://github.com/OHF-Voice/piper1-gpl, адрес — `HPG_TTS_URL`): браузер шлёт `POST <HPG_TTS_URL>/synthesize` с JSON `{"text": "…"}` и ждёт WAV, как у HTTP-сервера Piper `python3 -m piper.http_server`. Сервис должен разрешать CORS для адреса Grafana, а если Grafana открыта по HTTPS — сам быть по HTTPS. Без адреса или при ошибке остаётся сигнал. Только на периоде «до сейчас».

## Генератор дашборда

`resources/grafana/dashboards/queues.json` генерирует скрипт `resources/grafana/generate_queues_dashboard.py`: правки делаются в нём, JSON пересобирается.

```bash
python3 resources/grafana/generate_queues_dashboard.py [путь] [--prefix office]
```

- `путь` — куда записать JSON, по умолчанию `dashboards/queues.json` рядом со скриптом.
- `--prefix` — префикс метрик, `prometheus.default_namespace` приложения.

## Нагрузка тестовыми задачами

Нагрузка тестовыми задачами — временная команда `queue-metrics:load-test`, работает и на QA (в production не запускается). Ставит 10 разных задач в `default`, 5 в `slow` и 3 в `slow_reports`: быстрые и медленные, нестабильные, с таймаутом, исчерпывающие все три попытки (в Failed Jobs — Attempts 3) и одна, которая всегда падает. Сценарий — константа `SCENARIO` в команде. Нагрузка начинается через минуту после прогревочных джобов и идёт 2 минуты. Параметры — `--help`.

```bash
docker compose exec app php artisan queue-metrics:load-test
```

С `--continuous` команда работает до остановки и даёт поток, похожий на рабочий: у каждой задачи свой темп (константа `RATES`, от 120 джобов в минуту до одного в пять минут), темп плавно ходит волной ±50% с периодом 20 минут, раз в 5–12 минут — всплеск из 30–80 джобов одной задачи, раз в 15–30 минут — перегрузка одной очереди на 1–3 минуты (темп ×6–10 и сразу 30 джобов: выше двух лимитов воркеров), раз в 4–8 минут — тестовый батч (test_import и test_sync — в `slow`, test_report — в `slow_reports`). Провалов втрое меньше, чем в `SCENARIO`: каждый пишет строку в таблицу упавших джобов. Запуск в фоне и остановка:

```bash
docker compose exec -d app sh -c 'php artisan queue-metrics:load-test --continuous >> storage/logs/queue-load-test.log 2>&1'
```

```bash
docker compose exec app pkill -INT -f 'queue-metrics:load-test --continuous'
```

Убрать тестовые задачи с локального дашборда — удалить их ряды из Prometheus (admin API включён в профиле `monitoring`). Новых строк без новых попыток не появится: таблица задач показывает только классы с попытками за период.

```bash
curl -X POST http://localhost:9090/api/v1/admin/tsdb/delete_series --data-urlencode 'match[]={job_class=~"Test.*Job"}'
```

Провалы и таймауты пишутся в таблицу упавших джобов приложения (`queue.failed.table`).

## Как вынести джоб в отдельную очередь

**Джоб.** Очередь задаётся в конструкторе:

```php
class GenerateFinReportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(/* ... */)
    {
        $this->onQueue('reports');
    }
}
```

- Не объявляйте `public $queue = 'reports';` в джобе с трейтом `Queueable`: свойство `$queue` уже есть в трейте, PHP упадёт с fatal error о несовместимом свойстве.
- Уберите явные `->onQueue(...)` в местах постановки: они перекрывают конструктор.
- **Слушатель события:** свойство `public $queue = 'reports';` (если слушатель не использует `Queueable`) или метод `viaQueue()`.
- **Уведомление:** метод `viaQueues()` — очередь для каждого канала.
- **Почта:** `->onQueue('reports')` при отправке или в конструкторе mailable.

**Супервизор** в `config/horizon.php` → `defaults`:

```php
'supervisor-reports' => [
    'connection' => 'redis',
    'queue' => ['reports'],
    'balance' => 'auto',
    'autoScalingStrategy' => 'time',
    'minProcesses' => 1,
    'maxProcesses' => 2,      // сколько тяжёлых джобов одновременно
    'memory' => 256,
    'timeout' => 540,         // меньше retry_after соединения, иначе джоб выполнится дважды
    'tries' => 3,
    'backoff' => [30, 120],   // пауза перед повтором, с
    'nice' => 10,             // ниже приоритет процессов на CPU
],
```

- `timeout` (и `$timeout` джоба, он важнее) должен быть меньше `retry_after` соединения (`config/queue.php`). Иначе по истечении `retry_after` джоб получит второй воркер, пока первый ещё работает.
- Без `tries` Horizon не ограничивает число попыток.
- Отдельный супервизор изолирует процессы: медленные джобы не занимают воркеры `default`. Параллельность ограничивает `maxProcesses`, CPU — `nice`.
- Порог ожидания новой очереди — в `horizon.waits`: `'redis:reports' => 300`. По нему Horizon шлёт `LongWaitDetected`, а дашборд красит очередь и поднимает алерт.
- После выкладки Horizon должен перезапуститься (`php artisan horizon:terminate`). Новая очередь сама появится в метриках и на дашборде: список очередей берётся из `config/horizon.php`.

**Проверка старой очереди после удаления из конфига.** Очередь, которой нет в `config/horizon.php`, никто не обрабатывает, и в метриках её нет: джобы, попавшие туда, молча лежат. Проверьте её сразу после выкладки и ещё раз не раньше чем через 25 ч (самая долгая задержка в коде):

```bash
# Ожидают, отложены, взяты воркерами — все три должны быть 0
php artisan queue:monitor redis:<old_queue>
```

## История изменений

| Дата | Версия | Изменения |
|------|--------|-----------|
| 2026-10-12 | 1.0 | Создание документа |
| 2026-10-12 | 1.1 | `PROMETHEUS_ALLOWED_IPS`, ключи и префикс Redis, `office_queue_delayed_due_jobs`, ожидание без `delay()`, сброс счётчиков после рестарта Redis, проверка после удаления очереди, советы по слиянию ККТ |
| 2026-10-16 | 2.0 | Под новый дашборд «Queues»: убраны старый дашборд «Очереди Horizon» и его метрики, разборы «кто стопорит» и «что вынести из default», устаревший снимок проблем конфигурации; добавлены плагины Grafana, алерты, звук и речь |
| 2026-10-16 | 2.1 | Вынесено в пакет `sushi-market/horizon-prometheus-grafana`: установка, настройки, списки джобов, генератор дашборда, нагрузка |
