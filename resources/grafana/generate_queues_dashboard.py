"""Генерирует дашборд Grafana «Queues» (dashboards/queues.json рядом со скриптом).

    python3 generate_queues_dashboard.py [путь] [--prefix office]

--prefix — префикс метрик, prometheus.default_namespace приложения: запросы написаны с office_ и при другом
префиксе переписываются в готовом JSON.
"""
import argparse
import copy
import json
import os
import re
import sys

ARGS = argparse.ArgumentParser(description="Дашборд Grafana «Queues»")
ARGS.add_argument("output", nargs="?", default=os.path.join(os.path.dirname(os.path.abspath(__file__)), "dashboards", "queues.json"))
ARGS.add_argument("--prefix", default="office", help="префикс метрик: prometheus.default_namespace приложения")
OPTIONS = ARGS.parse_args()

# Источник Prometheus — из скрытой переменной datasource: по умолчанию тот, что выбран по умолчанию в Grafana
DS = {"type": "prometheus", "uid": "${datasource}"}

# Очереди из config/horizon.php: метрика публикуется для каждой, в том числе пустой
QUEUES = "group by (queue) (office_queue_pending_jobs)"
# 0, если метрики приложения вообще есть: отличает «ноль» от «нет данных»
ZERO_IF_METRICS = "on () 0 * group(office_horizon_status)"

ATTEMPT_LABELS = "queue, job_class, status"


def filter_is_all(variable):
    """Не пусто, если в фильтре выбрано «All»: текст фильтра (${var:text}) сравнивается с «All» через метки."""
    return (f'(label_replace(vector(1), "filter", "${{{variable}:text}}", "", "")'
            ' and on (filter) label_replace(vector(1), "filter", "All", "", ""))')


QUEUE_IS_ALL = filter_is_all("queue")
SUPERVISOR_IS_ALL = filter_is_all("supervisor")
BATCH_IS_ALL = filter_is_all("batch_kind")
# Очереди и классы джобов выбранных в фильтре Batch батчей: связь пишет подписчик метрик при отправке
BATCH_JOB_CLASSES = 'office_queue_batch_job_classes{batch=~"$batch_kind"}'


def batch_filtered(expr, label):
    """Сужает строки выражения до очередей или классов (label) выбранных батчей; при «All» в Batch — без сужения"""
    return (f"(({expr}) and on ({label}) group by ({label}) ({BATCH_JOB_CLASSES})"
            f" or ({expr}) and on () {BATCH_IS_ALL})")


# В Batch и Job «All»: показатели очереди считаются целиком, иначе — только по выбранным классам джобов
ALL_JOBS_SELECTED = f"({filter_is_all('job_class')} and on () {BATCH_IS_ALL})"


def by_job_selection(whole_queue, selected_jobs=None):
    """Пока выбраны все задачи — значение по очереди целиком; если выбраны батч или задачи — по их классам,
    а без selected_jobs — пусто (прочерк): по классам этих данных нет"""
    whole = f"(({whole_queue}) and on () {ALL_JOBS_SELECTED})"
    return whole if selected_jobs is None else f"{whole} or (({selected_jobs}) unless on () {ALL_JOBS_SELECTED})"


# Классы, которые ни разу не выполнялись: их очередь, супервизор и батч неизвестны, видны только без фильтров
UNFILTERED = f"{QUEUE_IS_ALL} and on () {SUPERVISOR_IS_ALL} and on () {BATCH_IS_ALL}"


def attempts_delta(statuses, window):
    """Попытки за период точно — разницей счётчика, без экстраполяции increase(). Начало — последнее
    значение за сутки до периода, поэтому дыра в сборе на границе не ломает подсчёт. У ряда, которого
    до периода не было, началом служит его первое значение: накопленное до первого сбора не считается.
    Сброс счётчиков после рестарта Redis (разница < 0) — значение после сброса."""
    selector = f'office_queue_jobs_total{{status=~"{statuses}"}}'
    def at(expr):
        return f"sum by ({ATTEMPT_LABELS}) ({expr})"
    now = at(selector)
    start = f"({at(f'last_over_time({selector}[1d] offset {window})')} or {at(f'min_over_time({selector}[{window}])')})"
    return f"(({now} - {start}) >= 0 or ({now} < {start}))"


def attempts_per_step(statuses):
    """Попытки за каждый шаг графика: точка t — прирост счётчика на (t, t + шаг], обрезанный периодом.
    Сумма точек равна attempts_delta за период при любом шаге: Grafana выравнивает начало графика
    по шагу, поэтому крайние шаги обрезаются началом и концом периода (счётчик не убывает: максимум
    с началом, минимум с концом). На краях шага берётся последнее значение внутри шага, а без него —
    текущее: метка устаревания после пропущенного сбора не обнуляет шаг. Если значения на начало шага
    нет (ряд появился или вернулся после дыры), началом служит первое значение внутри шага — попытки
    во время дыры теряются, но не пересчитываются целиком."""
    selector = f'office_queue_jobs_total{{status=~"{statuses}"}}'
    def at(expr):
        return f"sum by ({ATTEMPT_LABELS}) ({expr})"
    L = ATTEMPT_LABELS
    step_end = f"({at(f'last_over_time({selector}[$__interval] offset -$__interval)')} or {at(f'{selector} offset -$__interval')})"
    step_start = f"({at(f'last_over_time({selector}[$__interval])')} or {at(selector)})"
    step_first = at(f'min_over_time({selector}[$__interval] offset -$__interval)')
    period_end = at(f'{selector} @ ${{__to:date:seconds}}')
    period_start = at(f'last_over_time({selector}[1d] @ ${{__from:date:seconds}})')
    later = (f"(({step_end} <= {period_end}) or ({period_end} and on ({L}) {step_end}) "
             f"or ({step_end} unless on ({L}) {period_end}))")
    since = (f"(({step_start} >= {period_start}) or ({period_start} and on ({L}) {step_start}) "
             f"or ({step_start} unless on ({L}) {period_start}) or ({step_first} unless on ({L}) {step_start}))")
    return f"(({later} - {since}) >= 0 or ({later} < {since}))"


# Загруженность сейчас — по пулу супервизора, а не по очереди: занятые воркеры всех его очередей
# (среднее за BUSY_WINDOW) на его maxProcesses. Иначе две очереди общего супервизора по 50% выглядели бы
# полупустыми, хотя вместе уже упёрлись в потолок. Ряд reserved по классу есть, только пока класс
# выполняется, поэтому среднее — сумма выборок на число сборов (по ряду процессов, он есть всегда).
# Фильтры не действуют (queue=~".+" и job=False): при выбранной очереди пул всё равно считается целиком.
# Окно 15 с — 5 шагов балансировки Horizon (balanceCooldown 3 с): полоса успевает за масштабированием и не
# дёргается от коротких джобов. В окно должно попадать хотя бы одно измерение: при сборе реже 15 с — расширить
BUSY_WINDOW = "15s"
QUEUE_BUSY = (f'(sum by (queue) (sum_over_time(office_queue_jobs_reserved{{queue=~".+"}}[{BUSY_WINDOW}]))'
              f' / on (queue) max by (queue) (count_over_time(office_horizon_current_processes{{queue=~".+"}}[{BUSY_WINDOW}])))')
SUPERVISOR_QUEUES = "group by (supervisor, queue) (office_horizon_supervisor_processes)"
SUPERVISOR_BUSY = f"sum by (supervisor) ({SUPERVISOR_QUEUES} * on (queue) group_left () {QUEUE_BUSY})"
SUPERVISOR_UTIL = (f"clamp_max(({SUPERVISOR_BUSY} or on (supervisor) 0 * group by (supervisor) ({SUPERVISOR_QUEUES}))"
                   " / on (supervisor) (max by (supervisor) (office_horizon_supervisor_max_processes) > 0), 1)")
# Ровно на конец периода: таблица очередей (мгновенный запрос) и цвет плитки Supervisor Processes (по диапазону,
# последняя точка которого раньше конца на долю шага) так совпадают — окно в 15 с за секунды заметно меняется
SUPERVISOR_UTIL_AT_END = f"last_over_time(({SUPERVISOR_UTIL})[1s:1s] @ ${{__to:date:seconds}})"
SUPERVISOR_LOAD = f"max by (queue) ({SUPERVISOR_QUEUES} * on (supervisor) group_left () {SUPERVISOR_UTIL_AT_END})"

# Плитка Supervisor Processes: число процессов с зоной загруженности пула в дробной части — n + зона / 64
# (0 — до 70%, 1 — до 90%, 2 — выше, как у полосы Utilization, или Horizon не работает). Пока Horizon на паузе
# или остановлен, работающих процессов нет: число — 0, хотя на паузе процессы живы, но джобы не берут. Цвет у каждого супервизора свой, а пороги
# и Config from query results — одни на панель, поэтому цвет дают сопоставления значений: запрос B
# перечисляет значения за период с цветом по дробной части. Зона — на конец периода, как в таблице очередей.
# Доли 1/64 точны в двоичном виде, поэтому текст значения в метке count_values совпадает с ключом
# сопоставления; на спарклайне сдвиг не виден, а с decimals 0 число выводится целым. Фильтр Job загруженность
# не меняет — пул считается целиком
HORIZON_RUNNING = "(min(office_horizon_status) >= bool 1)"
HORIZON_STOPPED_AT_END = "((min(office_horizon_status @ ${__to:date:seconds}) < bool 1) or vector(0))"
PROCESSES_ZONE = (f"clamp_max(((round(100 * {SUPERVISOR_UTIL_AT_END}) >= bool 70)"
                  f" + (round(100 * {SUPERVISOR_UTIL_AT_END}) >= bool 90)"
                  " or on (supervisor) 0 * group by (supervisor) (office_horizon_supervisor_processes))"
                  f" + on () group_left () 2 * {HORIZON_STOPPED_AT_END}, 2)")
PROCESSES_VALUE = "(" + by_job_selection(
    'sum by (supervisor) (office_horizon_supervisor_processes{supervisor=~"$supervisor", queue=~"$queue"})',
    "sum by (supervisor) (group by (supervisor, queue) (office_horizon_supervisor_processes"
    '{supervisor=~"$supervisor", queue=~"$queue"}) * on (queue) group_left ()'
    ' sum by (queue) (office_queue_jobs_reserved{job_class=~"$job_class"}))',
) + f") * on () group_left () {HORIZON_RUNNING} + on (supervisor) ({PROCESSES_ZONE} / 64)"
PROCESSES_ZONE_COLORS = (("green", "[0-9]+"), ("yellow", "[0-9]+[.]015625"), ("red", "[0-9]+[.]03125"))


# Ячейка Utilization: полоса загруженности пула с процентом внутри. Встроенная полоса (gauge) пишет число только
# справа от себя, поэтому это Markdown + HTML, текст которой даёт сопоставление по шаблону. Значение —
# 1000 + процент, 1999 — загруженность неизвестна: строкой из четырёх цифр сортируется так же, как числом
# Зоны полосы: шаблон трёх цифр процента без ведущих нулей ($1$2), цвет заливки и текста
LOAD_ZONES = ((r"0(?:0(\d)|([1-6]\d))", "#73BF69", "#FFFFFF"),
              (r"0([78]\d)()", "#FADE2A", "#111217"),
              (r"(?:0(9\d)|(100))", "#F2495C", "#FFFFFF"))
UNKNOWN_LOAD = "999"


# Санитайзер HTML в Grafana вырезает flex и line-height, поэтому высота полосы задана через padding
def load_bar(fill, text):
    return (f'<div style="background: linear-gradient(90deg, {fill} $1$2%, rgba(204, 204, 220, 0.12) $1$2%);'
            f' border-radius: 3px; color: {text}; font-size: 12px; text-align: center; padding: 2px 0;">$1$2%</div>')


LOAD_CELL_MAPPINGS = [
    {"type": "regex", "options": {"pattern": f"/^1{UNKNOWN_LOAD}$/",
                                  "result": {"text": '<div style="text-align: center;">—</div>', "index": 0}}},
    *({"type": "regex", "options": {"pattern": f"/^1{pattern}$/",
                                    "result": {"text": load_bar(fill, text), "index": i + 1}}}
      for i, (pattern, fill, text) in enumerate(LOAD_ZONES)),
]

STATUS_EXPR = f"""max by (queue) (
    label_replace(2 * (max by (queue) (office_queue_oldest_pending_job_age) >= bool 300), "signal", "stuck", "", "")
  or label_replace(2 * (sum by (queue) (office_queue_pending_jobs) > bool 0) * on () group_left () (min(office_horizon_status) < bool 1), "signal", "horizon", "", "")
  or label_replace(max by (queue) (office_queue_oldest_pending_job_age) >= bool 60, "signal", "slow", "", "")
  or label_replace(sum by (queue) (increase(office_queue_jobs_total{{status=~"failed|timed_out"}}[15m])) > bool 0.5, "signal", "errors", "", "")
  or label_replace(0 * {QUEUES}, "signal", "base", "", "")
) and on (queue) {QUEUES}
or on () label_replace(vector(-1), "queue", "no queue metrics", "", "")"""


# Метрики с меткой queue и с меткой job_class: фильтры дашборда добавляются только туда, где метка есть
QUEUE_METRICS = {
    "office_queue_pending_jobs", "office_queue_oldest_pending_job_age", "office_horizon_current_processes",
    "office_queue_jobs_total", "office_queue_jobs_pending", "office_queue_jobs_reserved",
    "office_queue_job_duration_seconds_bucket", "office_queue_job_duration_seconds_sum",
    "office_queue_job_duration_seconds_count", "office_queue_job_duration_min_seconds",
    "office_queue_job_duration_max_seconds", "office_queue_delayed_jobs", "office_queue_delayed_due_jobs",
}
JOB_METRICS = QUEUE_METRICS - {
    "office_queue_pending_jobs", "office_queue_oldest_pending_job_age", "office_horizon_current_processes",
    "office_queue_delayed_jobs", "office_queue_delayed_due_jobs",
} | {"office_queue_job_classes"}


def filtered(expr, job):
    """Добавляет к селекторам метрик фильтры $queue и, если job, $job_class. Селектор со своим matcher-ом
    queue фильтр очереди не получает: так считается то, что не должно зависеть от выбранной очереди."""
    def add(match):
        name, labels = match.group(1), match.group(2)
        matchers = []
        if name in QUEUE_METRICS and not re.search(r"\bqueue\s*=", labels or ""):
            matchers.append('queue=~"$queue"')
        if job and name in JOB_METRICS:
            matchers.append('job_class=~"$job_class"')
        if not matchers:
            return match.group(0)
        inner = labels[1:-1] if labels else ""
        return name + "{" + ", ".join(matchers + ([inner] if inner else [])) + "}"

    return re.sub(r"\b(office_[a-z_]+)(\{[^}]*\})?", add, expr)


def target(ref, expr, instant=True, fmt=None, job=True):
    t = {
        "datasource": DS,
        "editorMode": "code",
        "expr": filtered(expr, job),
        "legendFormat": "__auto",
        "range": not instant,
        "instant": instant,
        "refId": ref,
    }
    if fmt:
        t["format"] = fmt
    return t


def stat(pid, title, x, targets, *, mappings=None, thresholds=None, color_mode="none",
         graph_mode="none", no_value="—", calcs=("lastNotNull",), value_range=None):
    return {
        "datasource": DS,
        "fieldConfig": {
            "defaults": {
                "color": {"mode": "thresholds"},
                "decimals": 0,
                "mappings": mappings or [],
                "noValue": no_value,
                "thresholds": thresholds or {"mode": "absolute", "steps": [{"color": "text", "value": None}]},
                # Разделение разрядов по локали браузера: 237 312 в русской, 237,312 в английской
                "unit": "locale",
                **({"min": value_range[0], "max": value_range[1]} if value_range else {}),
            },
            "overrides": [],
        },
        "gridPos": {"h": 5, "w": 4, "x": x, "y": 0},
        "id": pid,
        "options": {
            "colorMode": color_mode,
            "graphMode": graph_mode,
            "justifyMode": "center",
            "orientation": "auto",
            "percentChangeColorMode": "standard",
            "reduceOptions": {"calcs": list(calcs), "fields": "", "values": False},
            "showPercentChange": False,
            "textMode": "value",
            "wideLayout": True,
        },
        "targets": targets,
        "title": title,
        "type": "stat",
    }


def column(name, width=None, extra=None):
    props = []
    if width:
        props.append({"id": "custom.width", "value": width})
    props += extra or []
    return {"matcher": {"id": "byName", "options": name}, "properties": props}


def duration_label(expr, label, seconds_step=1):
    """Значение по очереди текстом в метке label: до 2 минут — «5 s» (с шагом seconds_step), дальше — «17 min».
    Число в метку превращает count_values; значение ряда — служебное"""
    return (f'(label_replace(count_values by (queue) ("{label}", round(({expr}) < 120, {seconds_step})), "{label}", "$1 s",'
            f' "{label}", "(.*)")'
            f' or label_replace(count_values by (queue) ("{label}", round((({expr}) >= 120) / 60)),'
            f' "{label}", "$1 min", "{label}", "(.*)"))')


# Колонка Oldest wait — «возраст старейшего / порог очереди из horizon.waits», например «5 s / 30 s», с цветом по
# порогу своей очереди, как у плитки Pending: выше порога — жёлтый (как LongWaitDetected у Horizon), выше двух — красный. Grafana не склеивает два
# числа в ячейке и красит колонку одними порогами на все строки, поэтому текст собирает запрос, а спереди — уровень:
# «2|5 s / 30 s»; сопоставления значений колонки отрезают уровень и задают по нему цвет. Без порога — только возраст
OLDEST_AGE = f"(max by (queue) (office_queue_oldest_pending_job_age) and on (queue) {QUEUES} or 0 * {QUEUES})"
QUEUE_WAIT_LIMIT = 'max by (queue) (office_horizon_wait_threshold_seconds{queue=~"$queue"})'
OLDEST_LEVEL = (f"({OLDEST_AGE} / on (queue) {QUEUE_WAIT_LIMIT} > bool 1)"
                f" + ({OLDEST_AGE} / on (queue) {QUEUE_WAIT_LIMIT} > bool 2)")
OLDEST_CELL = (
    'label_join(label_join(' + duration_label(OLDEST_AGE, "age")
    + ' * on (queue) group_left (limit) ' + duration_label(QUEUE_WAIT_LIMIT, "limit")
    + f' * on (queue) group_left (level) count_values by (queue) ("level", {OLDEST_LEVEL}),'
    ' "cell", " / ", "age", "limit"), "cell", "|", "level", "cell")'
    ' or on (queue) label_replace(' + duration_label(OLDEST_AGE, "age") + ', "cell", "0|$1", "age", "(.*)")'
)
# Wait p95 — так же, но без порога в тексте: он уже в Oldest wait. Без ожиданий за период (NaN) — прочерк
WAIT_P95 = ('(histogram_quantile(0.95, sum by (queue, le)'
            ' (increase(office_queue_job_wait_seconds_bucket{queue=~"$queue"}[$__range]))) >= 0)')
WAIT_P95_LEVEL = (f"({WAIT_P95} / on (queue) {QUEUE_WAIT_LIMIT} > bool 1)"
                  f" + ({WAIT_P95} / on (queue) {QUEUE_WAIT_LIMIT} > bool 2)")
WAIT_P95_CELL = (
    'label_join(' + duration_label(WAIT_P95, "p95", 0.1)
    + f' * on (queue) group_left (p95_level) count_values by (queue) ("p95_level", {WAIT_P95_LEVEL}),'
    ' "p95_cell", "|", "p95_level", "p95")'
    ' or on (queue) label_replace(' + duration_label(WAIT_P95, "p95", 0.1) + ', "p95_cell", "0|$1", "p95", "(.*)")'
)
OLDEST_CELL_MAPPINGS = [
    {"type": "regex", "options": {"pattern": "/^2\\|(.*)$/", "result": {"text": "$1", "color": "red", "index": 0}}},
    {"type": "regex", "options": {"pattern": "/^1\\|(.*)$/", "result": {"text": "$1", "color": "yellow", "index": 1}}},
    {"type": "regex", "options": {"pattern": "/^0\\|(.*)$/", "result": {"text": "$1", "index": 2}}},
]

panels = [
    stat(
        1, "Horizon",
        0,
        # Снизу — тонкая полоса статуса во времени, разрыв в ней — когда Horizon лежал или метрик не было:
        # max много больше 1, поэтому UP рисуется узкой полосой у края, а значения ≤ 0 уходят за шкалу.
        # Худшее значение за шаг, чтобы короткий перезапуск не терялся на длинном периоде; число — последнее
        [target("A", "min(min_over_time(office_horizon_status[$__interval])) or min(office_horizon_status)"
                     " or vector(-2)", instant=False)],
        graph_mode="area",
        value_range=(0, 10),
        # Текст и цвет задают сопоставления, собранные из запроса B, см. horizon ниже
        thresholds={"mode": "absolute", "steps": [{"color": "red", "value": None}]},
        color_mode="background",
    ),
    stat(
        3, "Supervisor Processes",
        4,
        # По супервизорам: число — текущие процессы, в подписи — имя без префикса «supervisor-» и потолок
        # maxProcesses («slow · max 10»),
        # на фоне — процессы во времени: видно, как Horizon добавлял и убирал воркеры. Если выбраны батч или
        # задачи — сколько воркеров супервизора заняты их джобами. Цвет — зона загруженности пула, см. PROCESSES_ZONE
        [{**target("A", f'label_replace(label_replace(({PROCESSES_VALUE}) * on (supervisor) group_left (max)'
                        ' count_values by (supervisor) ("max", max by (supervisor) (office_horizon_supervisor_max_processes)),'
                        ' "name", "$1", "supervisor", "(.*)"), "name", "$1", "supervisor", "supervisor-(.+)")',
                   instant=False, job=False),
          "legendFormat": "{{name}} · max {{max}}"}],
        graph_mode="area",
    ),
    stat(
        2, "Pending",
        8,
        # Если выбраны батч или задачи — только их джобы (разбор головы очереди, до pending_scan_limit)
        [target("A", by_job_selection("sum(office_queue_pending_jobs)",
                                      f"sum(office_queue_jobs_pending) or {ZERO_IF_METRICS}"), instant=False)],
        graph_mode="area",
    ),
    stat(
        8, "Completed",
        12,
        # Как «Ошибок»: на фоне выполненные за шаг, число — сумма шагов, то есть выполненные за период
        [target("A", f"sum({attempts_per_step('completed')}) or {ZERO_IF_METRICS}", instant=False)],
        graph_mode="area",
        calcs=("sum",),
    ),
    stat(
        9, "Delayed",
        16,
        # Срок ещё не наступил: delay при отправке и повторы в паузе backoff. С наступившим сроком
        # джоб уже в «Ожидают», поэтому такие вычитаются. По классам отложенных нет: при выбранных — прочерк
        [target("A", by_job_selection("sum(office_queue_delayed_jobs) - sum(office_queue_delayed_due_jobs)"),
                instant=False)],
        graph_mode="area",
    ),
    stat(
        4, "Failed",
        20,
        # На фоне — ошибки за каждый шаг: видно, когда был всплеск и идёт ли спад. Число — сумма шагов,
        # то есть ошибки за период, как в таблицах
        [target("A", f"sum({attempts_per_step('failed|timed_out')}) or {ZERO_IF_METRICS}", instant=False)],
        graph_mode="area",
        calcs=("sum",),
        # Без данных о попытках — по числу упавших, пороги по доле — из запросов, см. failed_tile
        thresholds={"mode": "absolute", "steps": [{"color": "green", "value": None}, {"color": "yellow", "value": 1},
                                                  {"color": "red", "value": 5}]},
        color_mode="background",
    ),
    {
        "datasource": DS,
        "fieldConfig": {
            "defaults": {
                "custom": {
                    "align": "auto",
                    "cellOptions": {"type": "auto"},
                    "filterable": False,
                    "inspect": False,
                },
                "decimals": 0,
                "mappings": [],
                "noValue": "—",
                "unit": "locale",
                "thresholds": {"mode": "absolute", "steps": [{"color": "text", "value": None}]},
            },
            "overrides": [
                column("Queue", extra=[{"id": "custom.minWidth", "value": 140}]),
                column("Utilization", 130, [
                    {"id": "custom.cellOptions", "value": {"type": "markdown"}},
                    # «undefined» — пустое значение строки-заглушки после перевода в строку
                    {"id": "mappings", "value": [*LOAD_CELL_MAPPINGS, {"type": "value", "options": {
                        "undefined": {"text": '<div style="text-align: center;">—</div>', "index": 99},
                    }}]},
                ]),
                # Пороги те же, что у статуса: WARN от минуты, ERROR от пяти
                # «возраст / порог», цвет — по порогу своей очереди, см. OLDEST_CELL
                column("Oldest wait", 130, [
                    {"id": "custom.cellOptions", "value": {"type": "color-text"}},
                    {"id": "custom.align", "value": "right"},
                    {"id": "mappings", "value": OLDEST_CELL_MAPPINGS},
                ]),
                # p95 ожидания свободного воркера за период; цвета — как у Oldest wait
                column("Wait p95", 100, [
                    {"id": "custom.cellOptions", "value": {"type": "color-text"}},
                    {"id": "custom.align", "value": "right"},
                    {"id": "mappings", "value": OLDEST_CELL_MAPPINGS},
                ]),
                column("Pending", 90),
                column("Completed", 100),
                column("Delayed", 90),
                column("Processes", 100),
                column("Failed", 80, [
                    {"id": "custom.cellOptions", "value": {"type": "color-text"}},
                    {"id": "thresholds", "value": {"mode": "absolute", "steps": [
                        {"color": "text", "value": None}, {"color": "red", "value": 1},
                    ]}},
                ]),
                column("Retries", 80),
                column("Timeouts", 90, [
                    {"id": "custom.cellOptions", "value": {"type": "color-text"}},
                    {"id": "thresholds", "value": {"mode": "absolute", "steps": [
                        {"color": "text", "value": None}, {"color": "red", "value": 1},
                    ]}},
                ]),
                column("Success rate", 110, [
                    # Без попыток за период — обычный прочерк, а не красный цвет нижнего порога
                    {"id": "mappings", "value": [{"type": "special", "options": {
                        "match": "null+nan", "result": {"text": "—", "color": "text", "index": 0},
                    }}]},
                    {"id": "unit", "value": "percentunit"},
                    {"id": "decimals", "value": 1},
                    {"id": "custom.cellOptions", "value": {"type": "color-text"}},
                    {"id": "thresholds", "value": {"mode": "absolute", "steps": [
                        {"color": "red", "value": None}, {"color": "yellow", "value": 0.9},
                        {"color": "text", "value": 0.99},
                    ]}},
                ]),
            ],
        },
        "gridPos": {"h": 6, "w": 24, "x": 0, "y": 5},
        "id": 5,
        "options": {
            "cellHeight": "md",
            "showHeader": True,
            "sortBy": [{"desc": True, "displayName": "Utilization"}],
        },
        "targets": [
            # Статус — здоровье очереди целиком, фильтр задачи на него не действует
            # Загруженность пула для ячейки Utilization, см. LOAD_CELL_MAPPINGS. Статус очереди (STATUS_EXPR) пока не выводится
            target("A", f"1000 + (round(100 * {SUPERVISOR_LOAD}) and on (queue) {QUEUES}"
                        f" or on (queue) {UNKNOWN_LOAD} + 0 * {QUEUES})", fmt="table", job=False),
            # Pending, Processes, Delayed, Oldest wait — по очереди целиком, а если выбраны батч или задачи —
            # по их классам: ждущие, заняты воркеров; отложенных и возраста по классам нет — прочерк.
            # Processes — работающие: пока Horizon на паузе или остановлен, 0, как на плитке Supervisor Processes
            target("B", by_job_selection("sum by (queue) (office_queue_pending_jobs)",
                                         f"sum by (queue) (office_queue_jobs_pending) and on (queue) {QUEUES}"
                                         f" or 0 * {QUEUES}"), fmt="table"),
            target("C", f"sum by (queue) ({attempts_delta('completed', '$__range')})"
                        f" and on (queue) {QUEUES} or 0 * {QUEUES}", fmt="table"),
            target("D", by_job_selection(f"sum by (queue) (office_horizon_current_processes)"
                                         f" * on () group_left () {HORIZON_RUNNING} and on (queue) {QUEUES}"
                                         f" or 0 * {QUEUES}",
                                         f"sum by (queue) (office_queue_jobs_reserved) and on (queue) {QUEUES}"
                                         f" or 0 * {QUEUES}"), fmt="table"),
            target("E", f"sum by (queue) ({attempts_delta('failed|timed_out', '$__range')})"
                        f" and on (queue) {QUEUES} or 0 * {QUEUES}", fmt="table"),
            target("F", by_job_selection(OLDEST_CELL), fmt="table"),
            # Как плитка Delayed: срок ещё не наступил
            target("G", by_job_selection(f"(sum by (queue) (office_queue_delayed_jobs)"
                                         f" - sum by (queue) (office_queue_delayed_due_jobs))"
                                         f" and on (queue) {QUEUES} or 0 * {QUEUES}"), fmt="table"),
            target("H", f"sum by (queue) ({attempts_delta('retried', '$__range')})"
                        f" and on (queue) {QUEUES} or 0 * {QUEUES}", fmt="table"),
            target("I", f"sum by (queue) ({attempts_delta('timed_out', '$__range')})"
                        f" and on (queue) {QUEUES} or 0 * {QUEUES}", fmt="table"),
            # Без попыток за период — пусто (прочерк)
            target("J", f"sum by (queue) ({attempts_delta('completed', '$__range')})"
                        f" / (sum by (queue) ({attempts_delta('completed|failed|timed_out', '$__range')}) > 0)",
                   fmt="table"),
            # p95 того, сколько джобы ждали свободного воркера до первой попытки (без delay()), за период.
            # Гистограмма — по очереди, без классов: при выбранных задачах или батче — прочерк
            # Цвет — по порогу очереди, как у Oldest wait, см. WAIT_P95_CELL
            target("K", by_job_selection(WAIT_P95_CELL), fmt="table"),
        ],
        "title": "Queues",
        "transformations": [
            {"id": "merge", "options": {}},
            {"id": "organize", "options": {
                # age, limit, level и p95, p95_level — части текста Oldest wait и Wait p95, значения F и K — служебные
                "excludeByName": {"Time": True, "age": True, "limit": True, "level": True, "Value #F": True,
                                  "p95": True, "p95_level": True, "Value #K": True},
                "includeByName": {},
                # Слева направо: загруженность пула, воркеры сейчас (процессы; границы — в заголовке
                # таблицы), очередь сейчас (ждут, отложены, возраст старейшего / порог), итоги за период (p95 ожидания, выполнено,
                # ошибки, из них таймауты, повторы, успешность)
                "indexByName": {"Time": 0, "queue": 1, "Value #A": 2, "Value #D": 3, "Value #B": 4,
                                "Value #G": 5, "cell": 6, "p95_cell": 7, "Value #C": 8, "Value #E": 9,
                                "Value #I": 10, "Value #H": 11, "Value #J": 12},
                "renameByName": {"queue": "Queue", "Value #A": "Utilization", "Value #B": "Pending",
                                 "Value #C": "Completed", "Value #G": "Delayed", "Value #D": "Processes",
                                 "Value #E": "Failed",
                                 "Value #H": "Retries", "Value #I": "Timeouts", "Value #J": "Success rate",
                                 "p95_cell": "Wait p95", "cell": "Oldest wait"},
            }},
            # Ячейка Markdown рисует только строковые поля
            {"id": "convertFieldType", "options": {"conversions": [
                {"targetField": "Utilization", "destinationType": "string"}]}},
        ],
        "type": "table",
    },
]

ATTEMPTS = "office_queue_jobs_total"
DURATION = "office_queue_job_duration_seconds"
BY_JOB = "(queue, job_class)"

# Строки таблицы задач: классы, которые сейчас ждут или выполняются, или выполнялись за период.
# Ряды счётчиков есть у всех классов, когда-либо выполнявшихся, поэтому нужен фильтр > 0
JOBS = (f"(group by {BY_JOB} (office_queue_jobs_pending) or group by {BY_JOB} (office_queue_jobs_reserved)"
        f" or group by {BY_JOB} (increase({ATTEMPTS}[$__range]) > 0))")
# Классы из кода, которые раньше выполнялись: строка в каждой очереди, где они выполнялись
IDLE = (f"group by {BY_JOB} ({ATTEMPTS}) and on (job_class) group by (job_class) (office_queue_job_classes)")
# Классы из кода, которые не выполнялись ни разу: очередь неизвестна (у office_queue_job_classes нет метки
# queue), поэтому строки есть, только пока в фильтрах очереди и супервизора «All»
NEVER = (f'(label_replace(group by (job_class) (office_queue_job_classes) unless on (job_class) '
         f'(group by (job_class) ({ATTEMPTS}) or group by (job_class) {JOBS}), "queue", "—", "", "")'
         f" and on () {UNFILTERED})")
# Строки таблицы задач: активные за период и все классы очередей из кода, невыполнявшиеся — с нулями
ROWS = f"({JOBS} or {IDLE} or {NEVER})"


def attempts(statuses):
    return f"sum by {BY_JOB} ({attempts_delta(statuses, '$__range')})"


def job_count(statuses):
    """Число попыток за период; 0 у классов без таких попыток, чтобы колонка не пустела."""
    return f"round({attempts(statuses)}) and on {BY_JOB} {ROWS} or 0 * {ROWS}"


DURATION_COUNT = f"sum by {BY_JOB} (increase({DURATION}_count[$__range]))"
MAX = f"max by {BY_JOB} (max_over_time(office_queue_job_duration_max_seconds[$__range]))"
# Оценка по bucket-ам: внутри bucket-а интерполяция и может превысить точный максимум, поэтому в таблице
# p95 ограничен максимумом. Без максимума (попытки до появления метрики) — оценка как есть
P95 = (f"(histogram_quantile(0.95, sum by (le, queue, job_class) (increase({DURATION}_bucket[$__range])))"
       f" and on {BY_JOB} ({DURATION_COUNT} > 0))")

panels.append({
    "datasource": DS,
    "fieldConfig": {
        "defaults": {
            "custom": {
                "align": "auto",
                "cellOptions": {"type": "auto"},
                "filterable": False,
                "inspect": False,
            },
            "decimals": 0,
            "mappings": [],
            "noValue": "—",
            "unit": "locale",
            "thresholds": {"mode": "absolute", "steps": [{"color": "text", "value": None}]},
        },
        "overrides": [
            column("Job", extra=[
                {"id": "custom.minWidth", "value": 180},
                {"id": "mappings", "value": [{"type": "regex", "options": {
                    "pattern": "/^(?:.*\\\\)?([^\\\\]+)$/", "result": {"text": "$1", "index": 0},
                }}]},
            ]),
            column("Queue", 110),
            column("Pending", 100),
            column("Failed", 80, [
                {"id": "custom.cellOptions", "value": {"type": "color-text"}},
                {"id": "thresholds", "value": {"mode": "absolute", "steps": [
                    {"color": "text", "value": None}, {"color": "red", "value": 1},
                ]}},
            ]),
            column("Retries", 95),
            column("Success rate", 110, [
                # Без попыток за период — обычный прочерк, а не красный цвет нижнего порога
                {"id": "mappings", "value": [{"type": "special", "options": {
                    "match": "null+nan", "result": {"text": "—", "color": "text", "index": 0},
                }}]},
                {"id": "unit", "value": "percentunit"},
                {"id": "decimals", "value": 1},
                {"id": "custom.cellOptions", "value": {"type": "color-text"}},
                {"id": "thresholds", "value": {"mode": "absolute", "steps": [
                    {"color": "red", "value": None}, {"color": "yellow", "value": 0.9},
                    {"color": "text", "value": 0.99},
                ]}},
            ]),
            column("Workers", 95),
            column("Min", 95, [
                {"id": "unit", "value": "s"}, {"id": "decimals", "value": 2},
            ]),
            column("Avg", 95, [
                {"id": "unit", "value": "s"}, {"id": "decimals", "value": 2},
            ]),
            column("p95", 90, [
                {"id": "unit", "value": "s"}, {"id": "decimals", "value": 2},
            ]),
            column("Max", 95, [
                {"id": "unit", "value": "s"}, {"id": "decimals", "value": 2},
            ]),
            column("Timeouts", 95, [
                {"id": "custom.cellOptions", "value": {"type": "color-text"}},
                {"id": "thresholds", "value": {"mode": "absolute", "steps": [
                    {"color": "text", "value": None}, {"color": "red", "value": 1},
                ]}},
            ]),
        ],
    },
    # Около 10 строк: высоту по содержимому Grafana не подбирает, остальное — прокруткой внутри таблицы
    "gridPos": {"h": 14, "w": 24, "x": 0, "y": 20},
    "id": 6,
    "options": {
        "cellHeight": "md",
        "showHeader": True,
        # Выполнявшиеся за период выше нулевых: у тех «Среднее» пустое
        "sortBy": [{"desc": True, "displayName": "Pending"}, {"desc": True, "displayName": "Avg"}],
    },
    "targets": [
        target("A", f"sum by {BY_JOB} (office_queue_jobs_pending) or 0 * {ROWS}", fmt="table"),
        target("B", job_count("failed|timed_out"), fmt="table"),
        target("C", job_count("retried"), fmt="table"),
        target("D", f"{attempts('completed')} / ({attempts('completed|failed|timed_out')} > 0)", fmt="table"),
        target("E", f"sum by {BY_JOB} (office_queue_jobs_reserved) or 0 * {ROWS}", fmt="table"),
        target("F", f"sum by {BY_JOB} (increase({DURATION}_sum[$__range])) / ({DURATION_COUNT} > 0)", fmt="table"),
        target("G", f"({P95} <= on {BY_JOB} {MAX}) or on {BY_JOB} ({MAX} < on {BY_JOB} {P95}) or {P95}", fmt="table"),
        target("H", job_count("timed_out"), fmt="table"),
        target("I", f"min by {BY_JOB} (min_over_time(office_queue_job_duration_min_seconds[$__range]))", fmt="table"),
        target("J", MAX, fmt="table"),
        # Строка-заглушка из прочерков, когда задач нет: иначе пустой период — одни заголовки без колонок Job и Queue
        target("Z", 'label_replace(label_replace(vector(1), "job_class", "—", "", ""), "queue", "—", "", "")'
                    f" unless on () (sum by {BY_JOB} (office_queue_jobs_pending) or 0 * {ROWS})", fmt="table"),
    ],
    "title": "Jobs",
    "transformations": [
        {"id": "merge", "options": {}},
        {"id": "organize", "options": {
            "excludeByName": {"Time": True, "Value #Z": True},
            "includeByName": {},
            "indexByName": {"Time": 0, "job_class": 1, "queue": 2, "Value #A": 3, "Value #B": 4, "Value #C": 5,
                            "Value #D": 6, "Value #E": 7, "Value #I": 8, "Value #F": 9, "Value #G": 10,
                            "Value #J": 11, "Value #H": 12},
            "renameByName": {"job_class": "Job", "queue": "Queue", "Value #A": "Pending", "Value #B": "Failed",
                             "Value #C": "Retries", "Value #D": "Success rate", "Value #E": "Workers",
                             "Value #F": "Avg", "Value #G": "p95", "Value #H": "Timeouts",
                             "Value #I": "Min", "Value #J": "Max"},
        }},
    ],
    "type": "table",
})

# Классы из кода, которые не выполнялись, идут с очередью «—» и только при «All» в фильтрах очереди и супервизора
JOB_METRIC_NAMES = r'{__name__=~"office_queue_jobs_total|office_queue_jobs_pending|office_queue_jobs_reserved"'
# При выбранном Batch — только классы его батчей
JOB_VARIABLE_ROWS = batch_filtered(
    r'group by (queue, job_class) (' + JOB_METRIC_NAMES + r', queue=~"$queue"}) '
    r'or label_replace(group by (job_class) (office_queue_job_classes) unless on (job_class) '
    r'group by (job_class) (' + JOB_METRIC_NAMES + r'}), "queue", "—", "", "") and on () ' + UNFILTERED,
    "job_class",
)
# Текст — класс без пространства имён; класс из нескольких очередей — один вариант
JOB_VARIABLE_QUERY = (
    r'query_result(group by (job_class, display) (label_replace(' + JOB_VARIABLE_ROWS + r', '
    r'"display", "$1", "job_class", "(?:.*\\\\)?([^\\\\]*)")))'
)

# Каждая точка — максимум за свой шаг ($__interval): на длинных периодах пики между точками не теряются.
# Если в окне шага нет ни одного сбора, берётся текущее значение, чтобы не было ложного нуля
PENDING_MAX = (
    "sum by (queue, job_class) (max_over_time(office_queue_jobs_pending[$__interval])) "
    "or sum by (queue, job_class) (office_queue_jobs_pending)"
)
# Ряд по классу есть, только пока его джобы ждут: нули для классов, ждавших за период, чтобы линии
# опускались до 0, а не обрывались. Метка job — класс без пространства имён, для легенды
PENDING_BY_JOB = (
    f"label_replace(({PENDING_MAX}) "
    'or 0 * group by (queue, job_class) (max_over_time(office_queue_jobs_pending[$__range])), '
    '"job", "$1", "job_class", "(?:.*\\\\\\\\)?([^\\\\\\\\]+)")'
)

# Лимит воркеров очереди — фактический maxProcesses её супервизора. Супервизор берётся по рядам процессов:
# у slow и slow_reports он общий, и лимит у них один на двоих
WORKER_LIMIT = ("max(office_horizon_supervisor_max_processes * on (supervisor) group_left ()"
                ' group by (supervisor) (office_horizon_supervisor_processes{queue=~"$queue"}))')



# Сразу после таблицы очередей
panels.insert(next(i for i, panel in enumerate(panels) if panel["title"] == "Queues") + 1, {
    "datasource": DS,
    "fieldConfig": {
        "defaults": {
            "color": {"mode": "palette-classic"},
            "custom": {
                "axisPlacement": "auto",
                "drawStyle": "line",
                "fillOpacity": 30,
                "gradientMode": "none",
                "lineInterpolation": "stepAfter",
                "lineWidth": 1,
                "pointSize": 4,
                "showPoints": "never",
                "spanNulls": False,
                "stacking": {"group": "A", "mode": "normal"},
            },
            "decimals": 0,
            "min": 0,
            "unit": "locale",
        },
        # «Всего» — линия поверх стопки: не складывается с задачами, без заливки
        "overrides": [{
            "matcher": {"id": "byFrameRefID", "options": "B"},
            "properties": [
                {"id": "custom.stacking", "value": {"group": "A", "mode": "none"}},
                {"id": "custom.fillOpacity", "value": 0},
                {"id": "custom.lineWidth", "value": 2},
                {"id": "color", "value": {"mode": "fixed", "fixedColor": "text"}},
                # Лимит воркеров и перегрузка — пороги на этой линии, а не ряды: ряд растягивал бы ось до
                # себя, и над ним всегда была бы цветная полоса. Порог в масштаб оси не входит: пока задач
                # меньше, его не видно. Пунктир и заливка выше: жёлтая от лимита, красная от 2x лимита
                {"id": "custom.thresholdsStyle", "value": {"mode": "dashed+area"}},
            ],
        }],
    },
    "gridPos": {"h": 9, "w": 24, "x": 0, "y": 11},
    "id": 7,
    # Без легенды: в узких панелях ряда она съедала график. Задачи видны в подсказке при наведении
    "options": {
        "legend": {"showLegend": False},
        "tooltip": {"hideZeros": True, "mode": "multi", "sort": "desc"},
    },
    "targets": [
        {**target("A", PENDING_BY_JOB, instant=False), "legendFormat": "{{job}}"},
        # Сумма максимумов задач за шаг: совпадает с верхом стопки, на коротких периодах — точная сумма в моменте.
        # Пустая очередь — ноль, но только пока метрика очереди собирается: без метрик — «No data», а не ноль
        {**target("B", f"sum({PENDING_MAX}) or 0 * group(office_queue_pending_jobs)", instant=False),
         "legendFormat": "Total"},
        # Пороги: лимит воркеров (все заняты, часть задач ждёт) и 2x лимита (перегрузка).
        # Config from query results забирает этот запрос из графика
        {**target("E", f"2 * {WORKER_LIMIT}"), "legendFormat": "overload"},
    ],
    # Пороги из запроса E: прозрачный от 0, жёлтый от лимита, красный от перегрузки. Без своего нижнего шага
    # Grafana подставляет зелёный, и весь фон ниже зеленел. Шаги идут в порядке полей: base, limit, overload
    "transformations": [
        {"id": "calculateField", "filter": {"id": "byRefId", "options": "E"}, "options": {
            "mode": "binary", "alias": "base", "binary": {
                "left": {"matcher": {"id": "byName", "options": "overload"}}, "operator": "*",
                "right": {"fixed": "0"}}}},
        {"id": "calculateField", "filter": {"id": "byRefId", "options": "E"}, "options": {
            "mode": "binary", "alias": "limit", "binary": {
                "left": {"matcher": {"id": "byName", "options": "overload"}}, "operator": "/",
                "right": {"fixed": "2"}}}},
        {"id": "organize", "filter": {"id": "byRefId", "options": "E"}, "options": {
            "indexByName": {"Time": 0, "base": 1, "limit": 2, "overload": 3}}},
        {"id": "configFromData", "options": {
            "configRefId": "E",
            "mappings": [
                {"fieldName": "base", "handlerKey": "threshold1",
                 "handlerArguments": {"threshold": {"color": "transparent"}}},
                {"fieldName": "limit", "handlerKey": "threshold1",
                 "handlerArguments": {"threshold": {"color": "yellow"}}},
                {"fieldName": "overload", "handlerKey": "threshold1",
                 "handlerArguments": {"threshold": {"color": "red"}}},
            ],
            "applyTo": {"id": "byFrameRefID", "options": "B"},
        }},
    ],
    # Отдельная панель на каждую очередь из фильтра Queue, в один ряд: задачи разных очередей не смешиваются
    "repeat": "queue",
    "repeatDirection": "h",
    "maxPerRow": 4,
    "title": "Pending by job: $queue",
    "type": "timeseries",
})


def row(pid, title, y, collapsed_panels=None):
    """Ряд дашборда. У свёрнутого ряда его панели хранятся внутри него, а не в общем списке."""
    return {"collapsed": collapsed_panels is not None, "gridPos": {"h": 1, "w": 24, "x": 0, "y": y}, "id": pid,
            "panels": collapsed_panels or [], "title": title, "type": "row"}


# Раскладка по рядам. Ряд забирает все панели ниже себя до следующего ряда, поэтому под «Overview»
# с плитками нужны свои ряды и у таблиц. Заголовки таблиц пустые: их название — у ряда
by_title = {panel["title"]: panel for panel in panels}
tiles = [by_title[t] for t in ("Horizon", "Supervisor Processes", "Pending", "Completed", "Delayed", "Failed")]

horizon = by_title["Horizon"]
# Подпись «Uptime» сверху, под ней — uptime за период, фон — по текущему статусу: зелёный — работает, жёлтый —
# работает, но за период лежал дольше допустимого (uptime ниже UPTIME_WARNING), красный — не работает.
# Stat показывает текст сопоставления значения, поэтому сопоставления собираются из запроса B (Config from query
# results, по строке на статус): у «работает» (1) — процент и зелёный или жёлтый, у «лежит» (0, −1) — процент
# и красный, без метрик (−2) — «No metrics». Процент становится текстом через count_values; отсечение вниз до десятых
UPTIME_WARNING = 99
UPTIME = "floor(1000 * avg_over_time(((min(office_horizon_status) >= bool 1) or vector(0))[$__range:$__interval])) / 10"
UPTIME_TEXT = ('label_replace(label_replace(count_values("text", ' + UPTIME + '), "text", "$1%", "text", "([0-9]+[.][0-9])"),'
               ' "text", "$1.0%", "text", "([0-9]+)")')
horizon["targets"].append({**target("B", " or ".join([
    *(f'(label_replace(label_replace({UPTIME_TEXT}, "status", "{status}", "", ""), "color", "{color}", "", "")'
      f' and on () ({UPTIME}{condition}))'
      for status, color, condition in (("1", "green", f" >= {UPTIME_WARNING}"), ("1", "yellow", f" < {UPTIME_WARNING}"),
                                       ("0", "red", ""), ("-1", "red", ""))),
    'label_replace(label_replace(label_replace(vector(1), "status", "-2", "", ""), "color", "red", "", ""),'
    ' "text", "No metrics", "", "")',
]), fmt="table"), "legendFormat": "__auto"})
horizon["transformations"] = [{"id": "configFromData", "options": {
    "configRefId": "B",
    "mappings": [
        {"fieldName": "status", "handlerKey": "mappings.value"},
        {"fieldName": "text", "handlerKey": "mappings.text"},
        {"fieldName": "color", "handlerKey": "mappings.color"},
        {"fieldName": "Time", "handlerKey": "__ignore"},
        {"fieldName": "Value", "handlerKey": "__ignore"},
    ],
    "applyTo": {"id": "byFrameRefID", "options": "A"},
}}]
horizon["fieldConfig"]["defaults"]["displayName"] = "Uptime"
horizon["options"].update({"textMode": "value_and_name", "text": {"titleSize": 14}})

# «RPM» (выполненные в минуту) над числом выполненных, как Jobs Per Minute в Horizon: там это успешно завершённые с последнего снимка
# (horizon:snapshot, у нас раз в 5 минут) на минуты с него — среднее за 0–5 минут со сбросом. Здесь — ровно
# за последние 5 минут, без скачков после снимка. Имя поля из запроса B
completed_tile = by_title["Completed"]
completed_tile["targets"].append({**target(
    "B", f"round(sum({attempts_delta('completed', '5m')}) / 5) or {ZERO_IF_METRICS} or vector(-1)",
), "legendFormat": "per_minute"})
completed_tile["transformations"] = [
    {"id": "configFromData", "options": {
        "configRefId": "B",
        "mappings": [{"fieldName": "per_minute", "handlerKey": "displayName"}],
        "applyTo": {"id": "byFrameRefID", "options": "A"},
    }},
    # RPM — выполненные в минуту, как Jobs Per Minute в Horizon
    {"id": "renameByRegex", "options": {"regex": "^(\\d+)$", "renamePattern": "RPM: $1"}},
    # Метрик нет (/metrics не отвечает): без подписи Grafana назвала бы ряд текстом запроса
    {"id": "renameByRegex", "options": {"regex": "^-1$", "renamePattern": "RPM: —"}},
]
completed_tile["options"]["textMode"] = "value_and_name"
# Размер подписи по умолчанию подбирается по плитке, и в узкой она переносилась на две строки
completed_tile["options"]["text"] = {"titleSize": 14}

# Failed: число — упавшие за период, а цвет — по их доле среди попыток, иначе в проде, где упавшие есть всегда,
# плитка не выходила бы из красного. Пороги — 1% (жёлтый) и 5% (красный) от попыток за период (запрос C),
# но не меньше 1 и 5 упавших: при малом числе попыток пара провалов не красит плитку. Подпись — доля успешных,
# как Success rate в таблицах (запрос B), с отсечением вниз: 99.96% — это 99.9%, а не 100%
failed_tile = by_title["Failed"]
ATTEMPTS_IN_RANGE = f"sum({attempts_delta('completed|failed|timed_out', '$__range')})"
failed_tile["targets"] += [
    {**target("B", f"floor(1000 * sum({attempts_delta('completed', '$__range')}) / ({ATTEMPTS_IN_RANGE} > 0)) / 10"
                   " or vector(-1)"), "legendFormat": "success"},
    {**target("C", f"clamp_min({ATTEMPTS_IN_RANGE}, 100) or vector(100)"), "legendFormat": "attempts"},
]
failed_tile["transformations"] = [
    {"id": "configFromData", "options": {
        "configRefId": "B",
        "mappings": [{"fieldName": "success", "handlerKey": "displayName"}],
        "applyTo": {"id": "byFrameRefID", "options": "A"},
    }},
    {"id": "renameByRegex", "options": {"regex": "^(\\d+)$", "renamePattern": "Success: $1.0%"}},
    {"id": "renameByRegex", "options": {"regex": "^(\\d+\\.\\d)$", "renamePattern": "Success: $1%"}},
    # Попыток за период не было
    {"id": "renameByRegex", "options": {"regex": "^-1$", "renamePattern": "Success: —"}},
    *({"id": "calculateField", "filter": {"id": "byRefId", "options": "C"}, "options": {
        "mode": "binary", "alias": alias, "binary": {
            "left": {"matcher": {"id": "byName", "options": "attempts"}}, "operator": "*",
            "right": {"fixed": share}}}} for alias, share in (("warning", "0.01"), ("critical", "0.05"))),
    {"id": "configFromData", "options": {
        "configRefId": "C",
        "mappings": [
            {"fieldName": "attempts", "handlerKey": "__ignore"},
            {"fieldName": "warning", "handlerKey": "threshold1", "handlerArguments": {"threshold": {"color": "yellow"}}},
            {"fieldName": "critical", "handlerKey": "threshold1", "handlerArguments": {"threshold": {"color": "red"}}},
        ],
        "applyTo": {"id": "byFrameRefID", "options": "A"},
    }},
]
failed_tile["options"]["textMode"] = "value_and_name"
failed_tile["options"]["text"] = {"titleSize": 14}

processes_tile = by_title["Supervisor Processes"]
processes_tile["options"].update({"textMode": "value_and_name", "text": {"titleSize": 14}, "colorMode": "background"})
processes_tile["fieldConfig"]["defaults"]["thresholds"] = {"mode": "absolute", "steps": [{"color": "green", "value": None}]}
processes_value = "count_values(\"value\", " + PROCESSES_VALUE + ")"
for color, pattern in PROCESSES_ZONE_COLORS:
    processes_value = f'label_replace({processes_value}, "color", "{color}", "value", "{pattern}")'
processes_tile["targets"].append({**target("B", processes_value, instant=False, fmt="table", job=False),
                                  "legendFormat": "__auto"})
processes_tile["transformations"] = [{"id": "configFromData", "options": {
    "configRefId": "B",
    "mappings": [
        {"fieldName": "value", "handlerKey": "mappings.value"},
        {"fieldName": "color", "handlerKey": "mappings.color"},
        {"fieldName": "Time", "handlerKey": "__ignore"},
        {"fieldName": "Value", "handlerKey": "__ignore"},
    ],
    "applyTo": {"id": "byFrameRefID", "options": "A"},
}}]

# Pending: число — ожидающие, а цвет — по тому, как долго ждёт самый старый джоб очереди относительно её порога
# из horizon.waits (office_horizon_wait_threshold_seconds): выше порога — жёлтый (как LongWaitDetected у Horizon), выше двух — красный; плитка — по
# худшей из выбранных очередей. Подпись — эта очередь и возраст её старейшего: «Oldest: 17 min · slow».
# Возраст есть только по очереди целиком, поэтому при выбранных задачах или батче — без цвета и «Oldest: —»
pending_tile = by_title["Pending"]
WAIT_RATIO = ("max by (queue) (office_queue_oldest_pending_job_age)"
              " / on (queue) max by (queue) (office_horizon_wait_threshold_seconds)")
WAIT_LEVEL = f"max(({WAIT_RATIO} > bool 1) + ({WAIT_RATIO} > bool 2))"
# Возраст старейшего в худшей очереди, если он не нулевой
# В скобках: сравнения связывают сильнее and, и без них «< 120» ниже применялось бы к фильтру, а не к возрасту
WORST_AGE = (f"((max by (queue) (office_queue_oldest_pending_job_age) and on (queue) topk(1, {WAIT_RATIO})) > 0"
             f" and on () {ALL_JOBS_SELECTED})")
# Цвет плитки по уровню 0 / 1 / 2 (зелёный / жёлтый / красный), а не по числу на ней. Пороги цвета сравниваются
# с числом, а уровень — не оно. Поэтому запрос уровня отдаёт X, порог красного — X, жёлтого — X − K (K больше любого
# числа на плитке): уровень 0 → X = 3K, оба порога недостижимы; 1 → X = K, жёлтый с нуля; 2 → X = 0, красный с нуля
LEVEL_COLOR_K = 1_000_000_000


def level_color_target(ref, level, **kwargs):
    return {**target(ref, f"{LEVEL_COLOR_K} * (({level}) < bool 1) * 2 + {LEVEL_COLOR_K} * (({level}) < bool 2)",
                     **kwargs), "legendFormat": "level"}


def level_color_transformations(ref):
    """Пороги жёлтого и красного из запроса уровня ref. Пороги ложатся в порядке полей, и Grafana берёт последний
    подходящий: жёлтый должен идти раньше красного"""
    return [
        *({"id": "calculateField", "filter": {"id": "byRefId", "options": ref}, "options": {
            "mode": "binary", "alias": alias, "binary": {
                "left": {"matcher": {"id": "byName", "options": "level"}}, "operator": "-",
                "right": {"fixed": shift}}}} for alias, shift in (("warning", str(LEVEL_COLOR_K)), ("critical", "0"))),
        {"id": "configFromData", "options": {
            "configRefId": ref,
            "mappings": [
                {"fieldName": "level", "handlerKey": "__ignore"},
                {"fieldName": "warning", "handlerKey": "threshold1", "handlerArguments": {"threshold": {"color": "yellow"}}},
                {"fieldName": "critical", "handlerKey": "threshold1", "handlerArguments": {"threshold": {"color": "red"}}},
            ],
            "applyTo": {"id": "byFrameRefID", "options": "A"},
        }},
    ]


pending_tile["targets"] += [
    {**target("B", f'label_join(count_values by (queue) ("v", round({WORST_AGE} < 120)), "display", " s · ", "v", "queue")'
                   f' or label_join(count_values by (queue) ("v", round(({WORST_AGE} >= 120) / 60)),'
                   f' "display", " min · ", "v", "queue")'
                   ' or on () label_replace(vector(1), "display", "—", "", "")', job=False), "legendFormat": "oldest"},
    level_color_target("C", f"({WAIT_LEVEL} and on () {ALL_JOBS_SELECTED}) or vector(0)", job=False),
]
pending_tile["transformations"] = [
    # Подпись — из метки display
    {"id": "labelsToFields", "filter": {"id": "byRefId", "options": "B"}, "options": {}},
    {"id": "configFromData", "options": {
        "configRefId": "B",
        "mappings": [{"fieldName": "display", "handlerKey": "displayName"}],
        "applyTo": {"id": "byFrameRefID", "options": "A"},
    }},
    {"id": "renameByRegex", "options": {"regex": "^(.+ · .+|—)$", "renamePattern": "Oldest: $1"}},
    *level_color_transformations("C"),
]
pending_tile["options"].update({"textMode": "value_and_name", "text": {"titleSize": 14}, "colorMode": "background"})
pending_tile["fieldConfig"]["defaults"]["thresholds"] = {"mode": "absolute", "steps": [{"color": "green", "value": None}]}
queues_table, pending_chart, jobs_table = by_title["Queues"], by_title["Pending by job: $queue"], by_title["Jobs"]
# Пороги по умолчанию — без зон: без данных о супервизорах (запрос E пуст) Grafana рисовала бы свои,
# зелёный фон и красный от 80
pending_chart["fieldConfig"]["defaults"]["thresholds"] = {"mode": "absolute", "steps": [{"color": "transparent", "value": None}]}
# Supervisor Processes — шире: в ней по значению на супервизор с подписью «имя · max N»
TILE_WIDTHS = {"Horizon": 2, "Supervisor Processes": 6, "Pending": 4, "Completed": 4, "Delayed": 3, "Failed": 3,
               "Alerts": 2}
tile_x = 0
for tile in tiles:
    tile["gridPos"].update({"x": tile_x, "y": 1, "w": TILE_WIDTHS[tile["title"]]})
    tile_x += tile["gridPos"]["w"]
# Последняя — плитка Alerts, её добавляет блок алертов ниже
assert tile_x + TILE_WIDTHS["Alerts"] == 24, tile_x
# Графики ожидающих по очередям — в «Current Workload» под таблицей очередей. Таблица задач — подробности,
# в ряду «Details», свёрнутом по умолчанию
queues_table["gridPos"]["y"], pending_chart["gridPos"]["y"], jobs_table["gridPos"]["y"] = 7, 12, 22
queues_table["gridPos"]["h"] = 5
queues_table["title"] = jobs_table["title"] = ""

# Выполненные по задачам за минуту — под ожидающими, той же раскладкой: если ожидающие растут, видно,
# встала ли очередь целиком или воркеры заняты другими задачами. Точная разница счётчика за шаг
COMPLETED_PER_MINUTE = f"sum by (queue, job_class) ({attempts_per_step('completed')}) / ($__interval_ms / 60000)"
completed_chart = copy.deepcopy(pending_chart)
completed_chart.update({
    "id": 14,
    "title": "Completed by job: $queue",
    "gridPos": {"h": 9, "w": 24, "x": 0, "y": 21},
    "targets": [
        {**target("A", f'label_replace({COMPLETED_PER_MINUTE}, "job", "$1", "job_class", "(?:.*\\\\\\\\)?([^\\\\\\\\]+)")',
                  instant=False), "legendFormat": "{{job}}"},
        {**target("B", f"sum({COMPLETED_PER_MINUTE}) or {ZERO_IF_METRICS}", instant=False), "legendFormat": "Total"},
    ],
})
completed_chart.pop("transformations")
completed_chart["fieldConfig"]["defaults"].update({"unit": "suffix:/min", "decimals": 1})
# Порогов лимита у выполненных нет
completed_chart["fieldConfig"]["overrides"][0]["properties"] = [
    p for p in completed_chart["fieldConfig"]["overrides"][0]["properties"] if p["id"] != "custom.thresholdsStyle"
]

# Батчи по видам (имя без чисел и uuid) за выбранный период: сколько их было, сколько в них джобов и доля
# обработанных (как у Horizon и Laravel: total минус pending, упавшие остаются в pending), сколько упало
# и чем закрылись. Running, Pending и Oldest — про выполняющиеся на конец периода. Очереди и супервизора
# у батча нет, фильтры не действуют. Считается по отдельным батчам из истории Prometheus: коллектор отдаёт
# батч, пока он выполняется и сутки после закрытия
BATCH_JOBS = 'sum by (batch) (office_queue_batch_jobs{{state="{}"}})'
BATCH_LAST = "last_over_time({}[$__range])"
# Батчи периода: закрытые внутри него или не закрытые к его концу
BATCHES_IN_RANGE = (
    "(group by (batch, name) ("
    + BATCH_LAST.format("office_queue_batch_closed_timestamp_seconds") + " >= ${__from:date:seconds})"
    " or (group by (batch, name) (" + BATCH_LAST.format("office_queue_batch_created_timestamp_seconds") + ")"
    " unless on (batch, name) " + BATCH_LAST.format("office_queue_batch_closed_timestamp_seconds") + "))"
)
BATCH_KINDS = f"group by (batch) ({BATCHES_IN_RANGE})"


def batch_jobs_in_range(state):
    return (f'sum by (batch) ({BATCH_LAST.format(f"office_queue_batch_job_counts{{state=\"{state}\"}}")}'
            f" and on (batch, name) {BATCHES_IN_RANGE})")


def batch_closed(status):
    """Закрытые с этим статусом в периоде. Батч — пара (batch, name): ряд прогресса меняется с метками"""
    return (f'count by (batch) (group by (batch, name) '
            f'({BATCH_LAST.format(f"office_queue_batch_progress{{status=\"{status}\"}}")})'
            f" and on (batch, name) {BATCHES_IN_RANGE}) or 0 * {BATCH_KINDS}")


# Батчи вида в таблице: последние 20 периода выбранного вида. Таблица повторяется по скрытой batch_title —
# её значения — выбранные виды фильтра Batch, текст — заголовок таблицы
BATCH_KIND = 'batch=~"$batch_title"'
BATCH_SELECTED = (
    f"topk by (batch) (20, {BATCH_LAST.format('office_queue_batch_created_timestamp_seconds{' + BATCH_KIND + '}')}"
    f" and on (batch, name) {BATCHES_IN_RANGE})"
)


def batch_value(metric, labels=""):
    """Последнее значение батча за период, только выбранные батчи вида"""
    selector = metric + "{" + ", ".join(filter(None, [BATCH_KIND, labels])) + "}"
    return f"max by (batch, name) ({BATCH_LAST.format(selector)} and on (batch, name) {BATCH_SELECTED})"


# Статус на конец периода: ряд прогресса меняется со статусом, поэтому закрытый берётся последним значением
# за период, а выполняющийся — только если выполняется на конец периода. Значение 1000 + процент — для полосы.
# id — для ссылки на батч в Horizon
BATCH_STATUS_PROGRESS = (
    "1000 + max by (batch, name, status, id) (("
    + BATCH_LAST.format('office_queue_batch_progress{' + BATCH_KIND + ', status!="Running"}')
    + ' or on (batch, name) office_queue_batch_progress{' + BATCH_KIND + ', status="Running"})'
    f" and on (batch, name) {BATCH_SELECTED})"
)
CREATED = BATCH_LAST.format("office_queue_batch_created_timestamp_seconds{" + BATCH_KIND + "}")
CLOSED = BATCH_LAST.format("office_queue_batch_closed_timestamp_seconds{" + BATCH_KIND + "}")
# Сколько шёл батч; у выполняющегося — сколько идёт к концу периода. У Failures время закрытия неизвестно
# (finished_at Laravel не ставит, коллектор отдаёт время создания) — прочерк, а не 0
BATCH_DURATION = (f"max by (batch, name) (((({CLOSED}) or on (batch, name) (0 * {CREATED} + ${{__to:date:seconds}}))"
                  f" - on (batch, name) {CREATED}) and on (batch, name) {BATCH_SELECTED})"
                  " unless on (batch, name) "
                  + BATCH_LAST.format('office_queue_batch_progress{' + BATCH_KIND + ', status="Failures"}'))
# Цвета как в Horizon: упавшие джобы — ошибка (красный), отмена — предупреждение (оранжевый),
# выполняющийся — серый
BATCH_STATUS_MAPPINGS = [{"type": "value", "options": {
    "Running": {"text": "Running", "color": "#8E8E8E", "index": 0},
    "Finished": {"text": "Finished", "color": "green", "index": 1},
    "Failures": {"text": "Failures", "color": "red", "index": 2},
    "Cancelled": {"text": "Cancelled", "color": "orange", "index": 3},
    # Строка-заглушка
    "—": {"text": "—", "color": "transparent", "index": 4},
}}]
BATCH_PROGRESS_MAPPINGS = [{"type": "regex", "options": {
    "pattern": f"/^1{UNKNOWN_LOAD}$/",
    "result": {"text": '<div style="text-align: center;">—</div>', "index": 1},
}}, {"type": "regex", "options": {
    "pattern": "/^1(?:0{1,2}(\\d{1,2})|(100))$/",
    "result": {"text": load_bar("#5794F2", "#FFFFFF"), "index": 0},
}}]


batches_table = {
    "datasource": DS,
    "fieldConfig": {
        "defaults": {
            "custom": {"align": "auto", "cellOptions": {"type": "auto"}, "filterable": False, "inspect": False},
            "decimals": 0,
            "mappings": [],
            "noValue": "—",
            "unit": "locale",
            "thresholds": {"mode": "absolute", "steps": [{"color": "text", "value": None}]},
        },
        "overrides": [
            column("Batch", extra=[{"id": "custom.minWidth", "value": 160}]),
            column("Batches", 90),
            column("Size", 90),
            column("Running", 100),
            column("Progress", 160, [
                {"id": "custom.cellOptions", "value": {"type": "markdown"}},
                {"id": "mappings", "value": BATCH_PROGRESS_MAPPINGS},
            ]),
            column("Pending", 90),
            column("Failed", 80, [
                {"id": "custom.cellOptions", "value": {"type": "color-text"}},
                {"id": "thresholds", "value": {"mode": "absolute", "steps": [
                    {"color": "text", "value": None}, {"color": "red", "value": 1},
                ]}},
            ]),
            column("Oldest", 100, [
                {"id": "unit", "value": "s"}, {"id": "decimals", "value": 0},
                {"id": "mappings", "value": [{"type": "value", "options": {
                    "-1": {"text": "—", "index": 0}}}]},
            ]),
            column("OK", 80),
            column("With failures", 120, [
                {"id": "custom.cellOptions", "value": {"type": "color-text"}},
                {"id": "thresholds", "value": {"mode": "absolute", "steps": [
                    {"color": "text", "value": None}, {"color": "yellow", "value": 1},
                ]}},
            ]),
            column("Cancelled", 100, [
                {"id": "custom.cellOptions", "value": {"type": "color-text"}},
                {"id": "thresholds", "value": {"mode": "absolute", "steps": [
                    {"color": "text", "value": None}, {"color": "red", "value": 1},
                ]}},
            ]),
        ],
    },
    "gridPos": {"h": 6, "w": 24, "x": 0, "y": 31},
    "id": 15,
    "options": {"cellHeight": "md", "showHeader": True,
                "sortBy": [{"desc": True, "displayName": "Running"}]},
    "targets": [
        # Сейчас, но только виды с батчами в периоде: коллектор отдаёт виды за сутки
        target("A", f"sum by (batch) (office_queue_batches_running) and on (batch) {BATCH_KINDS}", fmt="table"),
        # Значение 1000 + процент для ячейки-полосы; без джобов — 1999, прочерк. Пустое значение
        # после перевода в строку стало бы «undefined»
        target("B", f"1000 + (round(100 * ({batch_jobs_in_range('total')} - {batch_jobs_in_range('pending')})"
                    f" / ({batch_jobs_in_range('total')} > 0)) or {UNKNOWN_LOAD} + 0 * {BATCH_KINDS})", fmt="table"),
        # Ждут выполнения в выполняющихся батчах: у Laravel pending включает упавшие
        target("C", f"({BATCH_JOBS.format('pending')} - {BATCH_JOBS.format('failed')}) and on (batch) {BATCH_KINDS}",
               fmt="table"),
        target("D", f"{batch_jobs_in_range('failed')} or 0 * {BATCH_KINDS}", fmt="table"),
        target("I", f"count by (batch) ({BATCHES_IN_RANGE})", fmt="table"),
        target("J", batch_jobs_in_range("total"), fmt="table"),
        # Без выполняющихся -1 (прочерк): пустой запрос при соединении убрал бы колонку целиком
        target("E", f"max by (batch) (office_queue_batch_oldest_age_seconds) or -1 + 0 * {BATCH_KINDS}", fmt="table"),
        target("F", batch_closed("Finished"), fmt="table"),
        target("G", batch_closed("Failures"), fmt="table"),
        target("H", batch_closed("Cancelled"), fmt="table"),
    ],
    "title": "",
    "transformations": [
        {"id": "merge", "options": {}},
        {"id": "organize", "options": {
            "excludeByName": {"Time": True},
            # Период: сколько, размер, прогресс, упавшие; сейчас: выполняются, ждут, самый старый; чем закрылись
            "indexByName": {"Time": 0, "batch": 1, "Value #I": 2, "Value #J": 3, "Value #B": 4, "Value #D": 5,
                            "Value #A": 6, "Value #C": 7, "Value #E": 8, "Value #F": 9, "Value #G": 10,
                            "Value #H": 11},
            "renameByName": {"batch": "Batch", "Value #I": "Batches", "Value #J": "Size", "Value #B": "Progress",
                             "Value #D": "Failed", "Value #A": "Running", "Value #C": "Pending",
                             "Value #E": "Oldest", "Value #F": "OK", "Value #G": "With failures",
                             "Value #H": "Cancelled"},
        }},
        # Ячейка Markdown рисует только строковые поля
        {"id": "convertFieldType", "options": {"conversions": [
            {"targetField": "Progress", "destinationType": "string"}]}},
    ],
    "type": "table",
}

batch_list = {
    "datasource": DS,
    "fieldConfig": {
        "defaults": {
            "custom": {"align": "auto", "cellOptions": {"type": "auto"}, "filterable": False, "inspect": False},
            "decimals": 0,
            "mappings": [],
            "noValue": "—",
            "unit": "locale",
            "thresholds": {"mode": "absolute", "steps": [{"color": "text", "value": None}]},
        },
        "overrides": [
            column("Name", extra=[
                {"id": "custom.minWidth", "value": 220},
                {"id": "custom.cellOptions", "value": {"type": "markdown"}},
            ]),
            column("Status", 110, [
                {"id": "custom.cellOptions", "value": {"type": "color-background", "mode": "basic"}},
                {"id": "custom.align", "value": "center"},
                {"id": "mappings", "value": BATCH_STATUS_MAPPINGS},
            ]),
            column("Progress", 160, [
                {"id": "custom.cellOptions", "value": {"type": "markdown"}},
                # «undefined» — пустое значение строки-заглушки после перевода в строку
                {"id": "mappings", "value": [*BATCH_PROGRESS_MAPPINGS, {"type": "value", "options": {
                    "undefined": {"text": '<div style="text-align: center;">—</div>', "index": 99},
                }}]},
            ]),
            column("Jobs", 90),
            column("Pending", 90),
            column("Failed", 80, [
                {"id": "custom.cellOptions", "value": {"type": "color-text"}},
                {"id": "thresholds", "value": {"mode": "absolute", "steps": [
                    {"color": "text", "value": None}, {"color": "red", "value": 1},
                ]}},
            ]),
            # Время браузера, как в Horizon
            column("Created", 200, [{"id": "unit", "value": "dateTimeAsLocal"}]),
            column("Duration", 110, [{"id": "unit", "value": "s"}, {"id": "decimals", "value": 0}]),
        ],
    },
    "gridPos": {"h": 7, "w": 24, "x": 0, "y": 32},
    "id": 16,
    "options": {"cellHeight": "md", "showHeader": True,
                "sortBy": [{"desc": True, "displayName": "Created"}]},
    # Отдельная таблица на каждый вид батча из периода, как таблицы супервизоров
    "repeat": "batch_title",
    "repeatDirection": "v",
    "targets": [
        # Имя — ссылкой на батч в Horizon, HTML собирается в запросе: ссылка Grafana на поле была бы у каждой
        # ячейки, в том числе у прочерка в строке-заглушке
        target("A", 'label_replace(label_join(' + BATCH_STATUS_PROGRESS + ', "link", "\\t", "id", "name"), "link",'
                    ' "<a href=\\"${horizon_url}/batches/$1\\" target=\\"_blank\\">$2</a>", "link", "([^\\t]*)\\t(.*)")',
               fmt="table"),
        target("B", batch_value("office_queue_batch_job_counts", 'state="total"'), fmt="table"),
        # Ждут выполнения: у Laravel pending включает упавшие. У отменённого упавший может уже не числиться
        # в pending — без ограничения вышло бы −1
        target("C", f"clamp_min({batch_value('office_queue_batch_job_counts', 'state=\"pending\"')}"
                    f" - on (batch, name) {batch_value('office_queue_batch_job_counts', 'state=\"failed\"')}, 0)",
               fmt="table"),
        target("D", batch_value("office_queue_batch_job_counts", 'state="failed"'), fmt="table"),
        target("E", f"1000 * {batch_value('office_queue_batch_created_timestamp_seconds')}", fmt="table"),
        target("F", BATCH_DURATION, fmt="table"),
        # Строка-заглушка из прочерков, когда батчей нет: иначе остаются одни заголовки без колонки Name,
        # и таблица не тянется на всю ширину. Строки таблицы задаёт запрос A
        target("Z", 'label_replace(label_replace(label_replace(vector(1), "name", "—", "", ""), "status", "—", "", ""),'
                    ' "link", "—", "", "") unless on () (' + BATCH_STATUS_PROGRESS + ")",
               fmt="table", job=False),
    ],
    "title": "${batch_title:text}",
    "transformations": [
        {"id": "merge", "options": {}},
        {"id": "organize", "options": {
            # name нужен только для склейки строк запросов, видна ссылка link
            "excludeByName": {"Time": True, "batch": True, "name": True, "id": True, "Value #Z": True},
            "indexByName": {"Time": 0, "batch": 1, "name": 2, "link": 3, "status": 4, "Value #A": 5, "Value #B": 6,
                            "Value #C": 7, "Value #D": 8, "Value #E": 9, "Value #F": 10, "id": 11},
            "renameByName": {"link": "Name", "status": "Status", "Value #A": "Progress", "Value #B": "Jobs",
                             "Value #C": "Pending", "Value #D": "Failed", "Value #E": "Created",
                             "Value #F": "Duration"},
        }},
        # Ячейка Markdown рисует только строковые поля
        {"id": "convertFieldType", "options": {"conversions": [
            {"targetField": "Progress", "destinationType": "string"}]}},
    ],
    "type": "table",
}

# Текущая нагрузка по видам батчей — в «Current Workload» под таблицами супервизоров. Виды — с батчами
# за период и из фильтра Batch. Воркеры, выполненные и упавшие — по классам джобов вида
# (office_queue_batch_job_classes) в их очередях
BATCH_KINDS_SELECTED = (f"{BATCH_KINDS} and on (batch) group by (batch) "
                        "(last_over_time(office_queue_batch_created_timestamp_seconds{batch=~\"$batch_kind\"}[$__range]))")
BATCH_CLASSES = "group by (batch, queue, job_class) (office_queue_batch_job_classes)"


def per_batch_kind(per_class):
    """Значение по классам джобов (метки queue, job_class), сложенное по видам батчей, в которые они входят"""
    return (f"sum by (batch) ({BATCH_CLASSES} * on (queue, job_class) group_left () sum by (queue, job_class) "
            f"({per_class}))")


def kinds_or_zero(expr):
    return f"({expr}) and on (batch) {BATCH_KINDS_SELECTED} or 0 * {BATCH_KINDS_SELECTED}"


batch_workload = {
    "datasource": DS,
    "fieldConfig": {
        "defaults": {
            "custom": {"align": "auto", "cellOptions": {"type": "auto"}, "filterable": False, "inspect": False},
            "decimals": 0,
            "mappings": [],
            # Прочерк в колонках строки-заглушки: у видов из периода значение есть всегда
            "noValue": "—",
            "unit": "locale",
            "thresholds": {"mode": "absolute", "steps": [{"color": "text", "value": None}]},
        },
        "overrides": [
            column("Batch", extra=[{"id": "custom.minWidth", "value": 160}]),
            column("Running", 100),
            column("Progress", 160, [
                {"id": "custom.cellOptions", "value": {"type": "markdown"}},
                # «undefined» — пустое значение заглушки после перевода в строку
                {"id": "mappings", "value": [*BATCH_PROGRESS_MAPPINGS, {"type": "value", "options": {
                    "undefined": {"text": '<div style="text-align: center;">—</div>', "index": 99},
                }}]},
            ]),
            column("Pending", 90),
            column("Failed", 80, [
                {"id": "custom.cellOptions", "value": {"type": "color-text"}},
                {"id": "thresholds", "value": {"mode": "absolute", "steps": [
                    {"color": "text", "value": None}, {"color": "red", "value": 1},
                ]}},
            ]),
            column("Workers", 90),
            column("Oldest", 100, [
                {"id": "unit", "value": "s"}, {"id": "decimals", "value": 0},
                {"id": "mappings", "value": [{"type": "value", "options": {"-1": {"text": "—", "index": 0}}}]},
            ]),
            column("Completed", 110),
            column("RPM", 80),
        ],
    },
    "gridPos": {"h": 7, "w": 24, "x": 0, "y": 12},
    "id": 17,
    "options": {"cellHeight": "md", "showHeader": True,
                "sortBy": [{"desc": True, "displayName": "Running"}]},
    "targets": [
        target("A", kinds_or_zero("sum by (batch) (office_queue_batches_running)"), fmt="table", job=False),
        # Прогресс выполняющихся батчей вида: 1000 + процент для полосы, без выполняющихся — 1999 (прочерк)
        target("B", f"1000 + (round(100 * ({BATCH_JOBS.format('total')} - {BATCH_JOBS.format('pending')})"
                    f" / ({BATCH_JOBS.format('total')} > 0)) and on (batch) {BATCH_KINDS_SELECTED}"
                    f" or {UNKNOWN_LOAD} + 0 * {BATCH_KINDS_SELECTED})", fmt="table", job=False),
        # Ждут: у Laravel pending включает упавшие
        target("C", kinds_or_zero(f"clamp_min({BATCH_JOBS.format('pending')} - {BATCH_JOBS.format('failed')}, 0)"),
               fmt="table", job=False),
        # Упавшие за период, как «Failed» в таблицах очередей, а не только у выполняющихся сейчас батчей
        target("D", kinds_or_zero(per_batch_kind(attempts_delta("failed|timed_out", "$__range"))), fmt="table"),
        target("E", kinds_or_zero(per_batch_kind("office_queue_jobs_reserved")), fmt="table"),
        target("F", f"max by (batch) (office_queue_batch_oldest_age_seconds) and on (batch) {BATCH_KINDS_SELECTED}"
                    f" or -1 + 0 * {BATCH_KINDS_SELECTED}", fmt="table", job=False),
        target("G", kinds_or_zero(per_batch_kind(attempts_delta("completed", "$__range"))), fmt="table"),
        target("H", kinds_or_zero(f"round({per_batch_kind(attempts_delta('completed', '5m'))} / 5)"), fmt="table"),
        # Строка-заглушка из прочерков, когда батчей в периоде нет: иначе остаются одни заголовки без колонки Batch,
        # и таблица не тянется на всю ширину
        target("Z", f'label_replace(vector(1), "batch", "—", "", "") unless on () ({BATCH_KINDS_SELECTED})',
               fmt="table", job=False),
    ],
    "title": "Batches",
    "transformations": [
        {"id": "merge", "options": {}},
        {"id": "organize", "options": {
            "excludeByName": {"Time": True, "Value #Z": True},
            "indexByName": {"Time": 0, "batch": 1, "Value #A": 2, "Value #B": 3, "Value #C": 4, "Value #D": 5,
                            "Value #E": 6, "Value #F": 7, "Value #G": 8, "Value #H": 9},
            "renameByName": {"batch": "Batch", "Value #A": "Running", "Value #B": "Progress", "Value #C": "Pending",
                             "Value #D": "Failed", "Value #E": "Workers", "Value #F": "Oldest",
                             "Value #G": "Completed", "Value #H": "RPM"},
        }},
        # Ячейка Markdown рисует только строковые поля
        {"id": "convertFieldType", "options": {"conversions": [
            {"targetField": "Progress", "destinationType": "string"}]}},
    ],
    "type": "table",
}

# Выполненные, упавшие и ожидающие джобы построчно, как страницы Completed Jobs, Failed Jobs и Pending Jobs в Horizon:
# упавшие — на всю ширину, под ними выполненные и ожидающие (у ожидающих колонок меньше, она уже). Те же данные из Redis Horizon за период дашборда, но не дольше, чем
# Horizon их хранит: ожидающие — horizon.trim.pending минут, выполненные — horizon.trim.completed, упавшие —
# horizon.trim.failed (неделю). Ожидающие — поставленные в очередь за период и ещё не выполненные, в том числе
# уже взятые воркером. Новые сверху. Отдаёт приложение
# (GET /metrics/horizon/…-jobs), Grafana читает плагином Infinity. Фильтры — в формате queryparam: выбранные
# значения или $__all при «All», то есть без фильтра; Supervisor и Batch приложение само переводит в очереди и классы.
# Клик по джобу открывает его в Horizon: путь к странице джоба (horizon_path) отдаёт приложение, у списков
# он разный. Ссылку собирает запрос (JSONata), а колонка Job показывает её как HTML: ссылка Grafana на поле
# была бы у каждой ячейки, в том числе у прочерка в строке-заглушке
APP_DS = {"type": "yesoreyeram-infinity-datasource", "uid": "${app_api}"}
JOB_FILTER_PARAMS = "&".join(f"${{{name}:queryparam}}" for name in ("supervisor", "batch_kind", "queue", "job_class"))
JOB_TIME_FORMAT = [{"id": "unit", "value": "time:YYYY-MM-DD HH:mm:ss"}]
# Значки в заголовках — символы Unicode по мотивам иконок меню Horizon: свои иконки заголовок панели не принимает.
# У ⚠ — U+FE0E, текстовый вид: без него на части систем он рисуется цветным эмодзи
# Job — короткое имя класса ссылкой на джоб в Horizon; пустой список — строка-заглушка из прочерков, без ссылки:
# иначе остаются одни заголовки без колонки Job, и таблица не тянется на всю ширину
HORIZON_JOB_LINK = r'"<a href=\"${horizon_url}/" & horizon_path & "\" target=\"_blank\">" & $split(name, "\\")[-1] & "</a>"'
HORIZON_JOBS_SELECTOR = (
    f'$count(data) > 0 ? data.($merge([$, {{"job": {HORIZON_JOB_LINK}}}])) : [{{"job": "—", "queue": "—"}}]'
)


def horizon_jobs_table(pid, title, x, w, url, sort_by, columns, overrides):
    """Таблица списка джобов Horizon. Колонки — как у Horizon; Infinity отдаёт поля по алфавиту, поэтому
    их порядок задаёт организация полей"""
    names = ["Job", "Queue", *(column_spec["text"] for column_spec in columns)]
    return {
        "datasource": APP_DS,
        "fieldConfig": {
            "defaults": {
                "custom": {"align": "auto", "cellOptions": {"type": "auto"}, "filterable": False, "inspect": False},
                "mappings": [],
                "noValue": "—",
                "thresholds": {"mode": "absolute", "steps": [{"color": "text", "value": None}]},
            },
            "overrides": [
                column("Job", extra=[
                    {"id": "custom.minWidth", "value": 140},
                    {"id": "custom.cellOptions", "value": {"type": "markdown"}},
                ]),
                column("Queue", 90),
                # Как у Horizon: «0.14s», без перевода в миллисекунды
                column("Runtime", 80, [{"id": "unit", "value": "suffix:s"}, {"id": "decimals", "value": 2}]),
                *overrides,
            ],
        },
        "gridPos": {"h": 15, "w": w, "x": x, "y": 52},
        "id": pid,
        "options": {"cellHeight": "md", "showHeader": True, "sortBy": [{"desc": True, "displayName": sort_by}]},
        "targets": [{
            "refId": "A",
            "datasource": APP_DS,
            "type": "json",
            "source": "url",
            "format": "table",
            "parser": "backend",
            "url": f"{url}?limit=500&from=${{__from}}&to=${{__to}}&{JOB_FILTER_PARAMS}",
            "url_options": {"method": "GET", "data": ""},
            "root_selector": HORIZON_JOBS_SELECTOR,
            "columns": [
                {"selector": "job", "text": "Job", "type": "string"},
                {"selector": "queue", "text": "Queue", "type": "string"},
                *columns,
            ],
        }],
        "title": title,
        "transformations": [{"id": "organize", "options": {
            "indexByName": {name: index for index, name in enumerate(names)}}}],
        "type": "table",
    }


failed_jobs = horizon_jobs_table(19, "⚠︎ Failed Jobs", 0, 24, "/metrics/horizon/failed-jobs", "Failed", [
    {"selector": "exception", "text": "Exception", "type": "string"},
    {"selector": "attempts", "text": "Attempts", "type": "number"},
    {"selector": "runtime", "text": "Runtime", "type": "number"},
    {"selector": "failed_at", "text": "Failed", "type": "timestamp_epoch"},
], [
    # Exception — класс и сообщение исключения («QueryException: SQLSTATE…»), их отдаёт приложение. Job — по
    # ширине имени, а Exception занимает всё остальное; полный текст — в просмотре ячейки
    column("Job", 220),
    column("Exception", extra=[{"id": "custom.minWidth", "value": 200}, {"id": "custom.inspect", "value": True}]),
    column("Attempts", 75),
    column("Failed", 160, JOB_TIME_FORMAT),
])
completed_jobs = horizon_jobs_table(18, "✓ Completed Jobs", 0, 14, "/metrics/horizon/completed-jobs", "Completed", [
    {"selector": "queued_at", "text": "Queued", "type": "timestamp_epoch"},
    {"selector": "completed_at", "text": "Completed", "type": "timestamp_epoch"},
    {"selector": "runtime", "text": "Runtime", "type": "number"},
], [
    column("Queued", 160, JOB_TIME_FORMAT),
    column("Completed", 160, JOB_TIME_FORMAT),
])
pending_jobs = horizon_jobs_table(20, "◷ Pending Jobs", 14, 10, "/metrics/horizon/pending-jobs", "Queued", [
    {"selector": "queued_at", "text": "Queued", "type": "timestamp_epoch"},
], [
    column("Queued", 160, JOB_TIME_FORMAT),
])
# Отложенные — не список Horizon, а состояние очередей сейчас: срок ещё не наступил, период дашборда не применяется.
# Ближайшие сверху. Attempts больше нуля — повтор, который ждёт паузы перед следующей попыткой. Ссылки на Horizon нет:
# там отложенный виден только horizon.trim.pending минут с постановки
delayed_jobs = horizon_jobs_table(22, "⧖ Delayed Jobs", 14, 10, "/metrics/horizon/delayed-jobs", "Available", [
    {"selector": "attempts", "text": "Attempts", "type": "number"},
    {"selector": "queued_at", "text": "Queued", "type": "timestamp_epoch"},
    {"selector": "available_at", "text": "Available", "type": "timestamp_epoch"},
], [
    column("Attempts", 75),
    column("Queued", 160, JOB_TIME_FORMAT),
    column("Available", 160, JOB_TIME_FORMAT),
])
delayed_jobs["options"]["sortBy"] = [{"desc": False, "displayName": "Available"}]
delayed_jobs["targets"][0]["root_selector"] = (
    r'$count(data) > 0 ? data.($merge([$, {"job": $split(name, "\\")[-1]}])) : [{"job": "—", "queue": "—"}]'
)
# Выполненных больше всего — слева во всю высоту, справа друг под другом ожидающие и отложенные
completed_jobs["gridPos"]["y"] = pending_jobs["gridPos"]["y"] = failed_jobs["gridPos"]["y"] + failed_jobs["gridPos"]["h"]
completed_jobs["gridPos"]["h"] = pending_jobs["gridPos"]["h"] + delayed_jobs["gridPos"]["h"]
delayed_jobs["gridPos"]["y"] = pending_jobs["gridPos"]["y"] + pending_jobs["gridPos"]["h"]

# Подробности — два свёрнутых ряда с общим префиксом: вложенных рядов в этом формате дашборда нет.
# Свёрнутый ряд не выполняет запросы своих панелей. В «Details · Jobs» сначала графики ожидающих
# и выполненных по задачам, затем таблица задач. Сводка по видам батчей (batches_table) пока не выводится
pending_chart["gridPos"]["y"], completed_chart["gridPos"]["y"], jobs_table["gridPos"]["y"] = 20, 29, 38
batch_list["gridPos"]["y"] = 21
panels = [row(10, "Overview", 0), *tiles, row(11, "Current Workload", 6), queues_table, batch_workload,
          row(12, "Details · Jobs", 19, [pending_chart, completed_chart, jobs_table, failed_jobs, completed_jobs, pending_jobs,
                                          delayed_jobs]),
          row(13, "Details · Batches", 20, [batch_list])]

# Алерты дашборда (resources/grafana/alerting/horizon.yml пакета) — последней плиткой Overview: число правил
# не в норме (сработавших и копящих время до срабатывания), цвет — красный, если есть сработавшие, жёлтый — если
# только копящие или сработавший погас меньше ALERTS_STABILIZATION назад: ноль ещё не значит, что всё устоялось.
# Состояние алертов Grafana пишет в Prometheus рядами GRAFANA_ALERTS (история состояний алертов
# с бэкендом prometheus, docker-compose.override.yml), точкой на каждое вычисление правила, раз в минуту.
# Погасший алерт рядом больше не пишется, а Prometheus по умолчанию ищет точку за 5 минут: поэтому учитываются
# только алерты с точкой за ALERTS_WINDOW. Правила дашборда — по префиксу uid. Подробности — в Alerting
ALERTS_WINDOW = "90s"
ALERTS_STABILIZATION_MINUTES = 15
ALERTS_STABILIZATION = f"{ALERTS_STABILIZATION_MINUTES}m"


def alerts_selector(state=""):
    matchers = 'grafana_rule_uid=~"horizon-.*"' + (f', alertstate="{state}"' if state else "")
    return f"GRAFANA_ALERTS{{{matchers}}}"


def alerts_seen(state="", window=ALERTS_WINDOW):
    """Ряды алертов дашборда с точкой за window; state — фильтр состояния, например 'firing'"""
    return f"last_over_time({alerts_selector(state)}[{window}])"


# Подпись — состояние: «Firing», «Pending», при стабилизации — сколько минут она ещё продлится («Stable in 12m»,
# с округлением вверх, от последней точки сработавшего алерта; «min» в узкой плитке переносится), иначе «Stable».
# Подпись есть всегда: иначе число прыгало бы, когда она появляется
ALERTS_LAST_FIRING = f"max(max_over_time(timestamp({alerts_selector('firing')})[{ALERTS_STABILIZATION}:]))"
ALERTS_CAPTION = (
    f'label_replace(group({alerts_seen("firing")}), "display", "Firing", "", "")'
    f' or on () label_replace(group({alerts_seen("pending")}), "display", "Pending", "", "")'
    f' or on () label_replace(count_values("v", ceil(({ALERTS_STABILIZATION_MINUTES * 60} - (time() - {ALERTS_LAST_FIRING}))'
    ' / 60) > 0), "display", "Stable in ${1}m", "v", "(.*)")'
    ' or on () label_replace(vector(1), "display", "Stable", "", "")'
)


failed_tile = by_title["Failed"]
alerts_tile = stat(
    21, "Alerts", 0,
    [target("A", f"count(count by (grafana_rule_uid) ({alerts_seen()})) or vector(0)", instant=False)],
    graph_mode="area", color_mode="background",
    thresholds={"mode": "absolute", "steps": [{"color": "green", "value": None}]},
)
alerts_tile["targets"].append(level_color_target(
    "C", f"2 * group({alerts_seen('firing')}) or group({alerts_seen('pending')})"
         f" or group({alerts_seen('firing', ALERTS_STABILIZATION)}) or vector(0)"))
alerts_tile["targets"].append({**target("B", ALERTS_CAPTION), "legendFormat": "caption"})
alerts_tile["transformations"] = [
    {"id": "labelsToFields", "filter": {"id": "byRefId", "options": "B"}, "options": {}},
    {"id": "configFromData", "options": {
        "configRefId": "B",
        "mappings": [{"fieldName": "display", "handlerKey": "displayName"}],
        "applyTo": {"id": "byFrameRefID", "options": "A"},
    }},
    *level_color_transformations("C"),
]
alerts_tile["options"].update({"textMode": "value_and_name", "text": {"titleSize": 14}})
# Что горит — штатная кнопка Alert rules (колокольчик) в панели инструментов дашборда: правила, привязанные к нему
alerts_tile["gridPos"].update({"w": TILE_WIDTHS["Alerts"], "y": failed_tile["gridPos"]["y"],
                               "x": failed_tile["gridPos"]["x"] + failed_tile["gridPos"]["w"]})
panels.insert(panels.index(failed_tile) + 1, alerts_tile)

# Звук и речь при сработавшем алерте: Grafana сама их не играет, поэтому они — в JS панели Business Text. Панель над Overview,
# вне рядов (её не свернуть вместе с рядом), и всегда скрыта: JS прячет её и поднимает сетку дашборда на её строку,
# потому что у панели минимум одна строка сетки. Что горит, показывает плитка Alerts.
# Сигнал тревоги (по мотивам IEC 60601-1-8), когда сработавших правил дашборда становится больше, и повтор раз
# в ALERT_SOUND_REPEAT_MINUTES, пока горят; только на периоде «до сейчас». После сигнала — названия сработавших правил
# («Horizon is down. Queue wait over twice the limit.»): их синтезирует Piper по адресу из переменной
# tts_url, без адреса или при ошибке — только сигнал. Браузер даёт звук только после любого
# клика по странице: до него сигнал ждёт следующего обновления. На телевизоре — Chrome
# с --autoplay-policy=no-user-gesture-required
ALERT_SOUND_REPEAT_MINUTES = 5
ALERT_SOUND_JS = """
// Панель прячется, а вся сетка поднимается на её строку: отдельные панели Grafana расставляет сама и вернула бы
// на место, а отступ сетки целиком не трогает
const item = context.element.closest('.react-grid-item');
const grid = item && item.parentElement;
if (grid && !item.style.visibility) {
  const tops = [...new Set([...grid.children].map((child) => Math.round(child.getBoundingClientRect().top)))]
    .sort((a, b) => a - b);
  item.style.visibility = 'hidden';
  grid.style.marginTop = -(tops.length > 1 ? tops[1] - tops[0] : item.offsetHeight + 8) + 'px';
}

// Сработавшие правила: экземпляры GRAFANA_ALERTS, у правил по очередям — по экземпляру на очередь
const frame = ((context.panelData && context.panelData.series) || [])[0];
const column = (name) => {
  const field = frame && frame.fields.find((item) => item.name === name);
  const values = field ? field.values : [];
  return (index) => (values.get ? values.get(index) : values[index]);
};
const length = frame ? (frame.length !== undefined ? frame.length : (frame.fields[0] ? frame.fields[0].values.length : 0)) : 0;
const [ruleOf, nameOf] = [column('grafana_rule_uid'), column('alertname')];
const rules = new Map();
for (let index = 0; index < length; index++) {
  rules.set(ruleOf(index), nameOf(index));
}
const firing = rules.size;
// Фраза — названия сработавших правил. «2x» синтез читает как «two x», поэтому множитель — словами
const sayable = (name) => name.replace(/\\b2x\\b/g, 'twice the').replace(/\\b(\\d+)x\\b/g, '$1 times the');
const phrase = [...rules.values()].map((name) => sayable(name) + '.').join(' ');
const ttsUrl = context.grafana.replaceVariables('${tts_url}');

// Контекст звука создаётся, только когда пора звенеть или по клику: созданный до клика пользователя браузер
// не запускает и пишет об этом в консоль. Включается он кликом где угодно на странице, а на телевизоре с флагом
// автозапуска работает сразу
const REPEAT_MS = REPEAT_MINUTES * 60 * 1000;
const state = (window.__horizonAlertSound = window.__horizonAlertSound || { firing: 0, lastPlayed: 0, audio: null });
const AudioContextClass = window.AudioContext || window.webkitAudioContext;
const audio = () => (state.audio = state.audio || (AudioContextClass ? new AudioContextClass() : null));
if (!state.unlocking) {
  document.addEventListener('pointerdown', () => {
    const ctx = audio();
    if (ctx && ctx.state !== 'running') {
      ctx.resume().catch(() => {});
    }
  });
  state.unlocking = true;
}
// Сигнал тревоги высокого приоритета по мотивам IEC 60601-1-8 (мониторы в реанимации): пачка из 10 быстрых
// импульсов 3+2 / 3+2, тон square — с гармониками, как требует стандарт. Синтезирует сам браузер, поэтому звучит
// и тогда, когда речь не получилась. Возвращает время, когда он закончится
const PULSES = [
  [0, 523], [0.19, 659], [0.38, 784], [0.75, 659], [0.94, 784],
  [1.5, 523], [1.69, 659], [1.88, 784], [2.25, 659], [2.44, 784],
];
const alarm = (ctx) => {
  const start = ctx.currentTime;
  const length = 0.12;
  PULSES.forEach(([at, freq]) => {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'square';
    osc.frequency.value = freq;
    // Плавные края: резкое включение и выключение щёлкает в динамиках
    gain.gain.setValueAtTime(0.0001, start + at);
    gain.gain.exponentialRampToValueAtTime(0.13, start + at + 0.02);
    gain.gain.setValueAtTime(0.13, start + at + length - 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + at + length);
    osc.connect(gain).connect(ctx.destination);
    osc.start(start + at);
    osc.stop(start + at + length);
  });
  return start + PULSES[PULSES.length - 1][0] + length;
};
const live = String(context.grafana.timeRange.raw.to) === 'now';
// Речь: WAV от Piper, проигрывается после сигнала тревоги. Ошибка сети или сервиса — остаётся сигнал
const speak = (ctx) => {
  if (!ttsUrl || ttsUrl.startsWith('$')) {
    return;
  }
  fetch(ttsUrl + '/synthesize', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ text: phrase }),
  })
    .then((response) => (response.ok ? response.arrayBuffer() : Promise.reject(response.status)))
    .then((data) => ctx.decodeAudioData(data))
    .then((buffer) => {
      const source = ctx.createBufferSource();
      source.buffer = buffer;
      source.connect(ctx.destination);
      source.start(Math.max(ctx.currentTime, state.speechAfter || 0));
    })
    .catch((error) => console.warn('Alert speech failed', error));
};
const due = live && firing > 0 && (firing > state.firing || Date.now() - state.lastPlayed >= REPEAT_MS);
if (!due) {
  state.firing = live ? firing : 0;
} else {
  // Пока браузер звук не разрешил, сигнал ждёт следующего обновления
  const ctx = audio();
  if (ctx && ctx.state === 'running') {
    // Фраза — сразу после сигнала тревоги
    state.speechAfter = alarm(ctx) + 0.3;
    speak(ctx);
    state.lastPlayed = Date.now();
    state.firing = firing;
  }
}
""".replace("REPEAT_MINUTES", str(ALERT_SOUND_REPEAT_MINUTES))

alert_sound = {
    "datasource": DS,
    "gridPos": {"h": 1, "w": 24, "x": 0, "y": 0},
    "id": 23,
    "options": {
        "afterRender": ALERT_SOUND_JS.strip(),
        "content": " ",
        "contentPartials": [],
        "defaultContent": " ",
        "editor": {"format": "auto", "language": "html"},
        "editors": ["afterRender"],
        "externalStyles": [],
        "helpers": "",
        "renderMode": "data",
        "styles": "",
        "wrap": False,
    },
    "targets": [target("A", alerts_seen("firing"), fmt="table")],
    "title": "",
    "transparent": True,
    "type": "marcusolsson-dynamictext-panel",
}
for panel in panels:
    panel["gridPos"]["y"] += alert_sound["gridPos"]["h"]
    for child in panel.get("panels", []):
        child["gridPos"]["y"] += alert_sound["gridPos"]["h"]
panels.insert(0, alert_sound)

# Таблица очередей — отдельная на каждый супервизор (повтор по скрытой переменной supervisor): видно,
# какие очереди делят пул процессов. Связь очереди с супервизором — последняя известная за сутки:
# пока Horizon лежит, его супервизоров в метриках нет, а таблицы со статусом ERROR должны остаться
# Фильтр Supervisor — имена супервизоров, известных за сутки. Фильтр Queue предлагает очереди выбранных
# супервизоров, а при «All» в Supervisor — и очереди без супервизора: их ожидающие никто не разбирает.
# «All» в Queue — список этих очередей, а не .*: так фильтр супервизора действует на все панели,
# у метрик очередей нет метки supervisor
SUPERVISOR_FILTER_QUERY = ('query_result(group by (supervisor)'
                           ' (last_over_time(office_horizon_supervisor_processes[1d])))')
# При выбранном Batch — только очереди его батчей
QUEUE_FILTER_QUERY = "query_result(" + batch_filtered(
    "group by (queue) (office_queue_pending_jobs) and on (queue) group by (queue)"
    ' (last_over_time(office_horizon_supervisor_processes{supervisor=~"$supervisor"}[1d]))'
    " or (group by (queue) (office_queue_pending_jobs) unless on (queue) group by (queue)"
    f" (last_over_time(office_horizon_supervisor_processes[1d])) and on () {SUPERVISOR_IS_ALL})",
    "queue",
) + ")"

# Скрытая переменная supervisor_title, по ней повторяется таблица: значение — имя супервизора, текст — заголовок
# «имя · Processes: [min: 1, max: 10] · Balancing: Auto · Connection: redis». Заголовок показывает
# только переменные, а их Grafana перезапрашивает лишь при загрузке страницы и смене периода, поэтому
# в нём только настройки, которые меняются с деплоем; текущие процессы — в колонках таблицы.
# Числа превращаются в метки через count_values, режим и соединение — метки office_horizon_supervisor_info.
# Супервизоры — выбранные в фильтре и с выбранными очередями, известные за сутки; пока Horizon лежит,
# в тексте только имя
KNOWN_SUPERVISORS = ('group by (supervisor) (last_over_time('
                     'office_horizon_supervisor_processes{supervisor=~"$supervisor", queue=~"$queue"}[1d]))')
SUPERVISOR_COUNTS = (
    'count_values by (supervisor) ("min", max by (supervisor) (office_horizon_supervisor_min_processes))'
    ' * on (supervisor) group_left (max)'
    ' count_values by (supervisor) ("max", max by (supervisor) (office_horizon_supervisor_max_processes))'
)
SUPERVISOR_FACTS = f"{SUPERVISOR_COUNTS} * on (supervisor) group_left (balance, connection) office_horizon_supervisor_info"
for mode in ("auto", "simple", "off"):
    SUPERVISOR_FACTS = f'label_replace({SUPERVISOR_FACTS}, "balancing", "{mode.capitalize()}", "balance", "{mode}")'
SUPERVISOR_TITLE = (
    f'label_replace(label_join({SUPERVISOR_FACTS}, "joined", ";", "supervisor", "min", "max", "balancing",'
    ' "connection"), "display", "$1 · Processes: [min: $2, max: $3] · Balancing: $4 · Connection: $5",'
    ' "joined", "(.*);(.*);(.*);(.*);(.*)")'
)
# Без супервизоров (период без данных, очередь без супервизора) — одна таблица с заголовком «—», а не «All»
SUPERVISOR_VARIABLE_QUERY = (f"query_result(({SUPERVISOR_TITLE} and on (supervisor) {KNOWN_SUPERVISORS})"
                             f' or on (supervisor) label_replace({KNOWN_SUPERVISORS}, "display", "$1", "supervisor", "(.*)")'
                             ' or on () label_replace(label_replace(vector(1), "supervisor", "—", "", ""),'
                             ' "display", "—", "", ""))')
for t in queues_table["targets"]:
    t["expr"] = (f"({t['expr']}) and on (queue) group by (queue)"
                 ' (last_over_time(office_horizon_supervisor_processes{supervisor=~"$supervisor_title"}[1d]))')
# Строка-заглушка из прочерков, когда очередей нет: иначе остаются одни заголовки без колонки Queue,
# и таблица не тянется на всю ширину. Строки таблицы задаёт запрос A — у каждой очереди он есть всегда
queues_table["targets"].append({
    **queues_table["targets"][0],
    "refId": "Z",
    "expr": f'label_replace(vector(1), "queue", "—", "", "") unless on () ({queues_table["targets"][0]["expr"]})',
})
next(t for t in queues_table["transformations"] if t["id"] == "organize")["options"]["excludeByName"]["Value #Z"] = True
queues_table.update({"title": "${supervisor_title:text}", "repeat": "supervisor_title", "repeatDirection": "v"})

FILTER_ORDER = ["supervisor", "batch_kind", "queue", "job_class", "supervisor_title", "batch_title"]

# Скрытая переменная batch_title, по ней повторяются таблицы батчей: значение — вид батча из фильтра Batch,
# текст — заголовок «вид · Queue: slow · Connection: redis». Очереди и соединения — office_queue_batch_info,
# через запятую, если их несколько; без связей за неделю — только вид
BATCH_TITLE = (r'label_replace(label_join(office_queue_batch_info and on (batch) ' + BATCH_KINDS_SELECTED
               + r', "display", "\t", "batch", "queue", "connection"), "display", "$1 · Queue: $2 · Connection: $3",'
               r' "display", "([^\t]*)\t([^\t]*)\t(.*)")')
# Без видов батчей в периоде — одна таблица с заголовком «—», а не «All»
BATCH_VARIABLE_QUERY = (f"query_result({BATCH_TITLE} or on (batch) label_replace({BATCH_KINDS_SELECTED}"
                        ' unless on (batch) office_queue_batch_info, "display", "$1", "batch", "(.*)")'
                        ' or on () label_replace(label_replace(vector(1), "batch", "—", "", ""), "display", "—", "", ""))')

dashboard = {
    "annotations": {"list": [{
        "builtIn": 1,
        "datasource": {"type": "grafana", "uid": "-- Grafana --"},
        "enable": True,
        "hide": True,
        "iconColor": "rgba(0, 211, 255, 1)",
        "name": "Annotations & Alerts",
        "type": "dashboard",
    }]},
    "editable": True,
    "fiscalYearStartMonth": 0,
    "graphTooltip": 0,
    "id": None,
    "links": [
        # Сброс всех фильтров разом: ссылка на этот же дашборд со всеми переменными в All, период сохраняется.
        # Без явных значений в URL Grafana оставила бы выбранные фильтры. Адрес — по uid текущего дашборда:
        # в другой Grafana он может лежать иначе, а префикс подпути Grafana добавляет к ссылке сама
        {
            "asDropdown": False, "icon": "", "includeVars": False, "keepTime": True, "tags": [],
            "targetBlank": False, "title": "Reset", "tooltip": "", "type": "link",
            "url": "/d/${__dashboard.uid}?" + "&".join(f"var-{name}=%24__all" for name in FILTER_ORDER),
        },
        {
            "asDropdown": False, "icon": "external link", "includeVars": False, "keepTime": False, "tags": [],
            "targetBlank": True, "title": "Horizon", "tooltip": "", "type": "link", "url": "${horizon_url}",
        },
    ],
    "panels": panels,
    "preload": False,
    # Как верхняя граница интервала сбора в проде: чаще новых точек нет. Минимум — min_refresh_interval Grafana (5s).
    # Grafana принимает только интервал из списка timepicker.refresh_intervals, а в стандартном 15s нет
    "refresh": "15s",
    "schemaVersion": 42,
    "tags": ["horizon", "queue", "office"],
    "templating": {"list": [
        {
            "current": {"text": ["All"], "value": ["$__all"]},
            "datasource": DS,
            "definition": SUPERVISOR_FILTER_QUERY,
            "includeAll": True,
            "allValue": ".*",
            "label": "Supervisor",
            "multi": True,
            "name": "supervisor",
            "options": [],
            "query": SUPERVISOR_FILTER_QUERY,
            "refresh": 2,
            "regex": '/supervisor="([^"]+)"/',
            "sort": 1,
            "type": "query",
        },
        {
            "current": {"text": ["All"], "value": ["$__all"]},
            "datasource": DS,
            "definition": QUEUE_FILTER_QUERY,
            "includeAll": True,
            "label": "Queue",
            "multi": True,
            "name": "queue",
            "options": [],
            "query": QUEUE_FILTER_QUERY,
            "refresh": 2,
            "regex": '/queue="([^"]+)"/',
            "sort": 1,
            "type": "query",
        },
        {
            "current": {"text": ["All"], "value": ["$__all"]},
            "datasource": DS,
            "definition": JOB_VARIABLE_QUERY,
            # «All» — список задач из меню, а не .*: так фильтр Batch действует на все панели
            "includeAll": True,
            "label": "Job",
            "multi": True,
            "name": "job_class",
            "options": [],
            "query": JOB_VARIABLE_QUERY,
            "refresh": 2,
            # Текст — класс без пространства имён, значение — полное имя класса
            "regex": '/display="(?<text>[^"]+)".*job_class="(?<value>[^"]+)"/',
            "sort": 1,
            "type": "query",
        },
        # Фильтр Batch — виды с батчами в периоде; по нему повторяется таблица батчей. Очереди и супервизора
        # у батча нет, на остальные панели фильтр не действует
        {
            "current": {"text": ["All"], "value": ["$__all"]},
            "datasource": DS,
            "definition": f"query_result({BATCH_KINDS})",
            "includeAll": True,
            "allValue": ".*",
            "label": "Batch",
            "multi": True,
            "name": "batch_kind",
            "options": [],
            "query": f"query_result({BATCH_KINDS})",
            "refresh": 2,
            "regex": '/batch="([^"]+)"/',
            "sort": 1,
            "type": "query",
        },
        # Скрытая: по ней повторяется таблица очередей, текст — заголовок таблицы
        {
            "current": {"text": ["All"], "value": ["$__all"]},
            "datasource": DS,
            "definition": SUPERVISOR_VARIABLE_QUERY,
            "hide": 2,
            "includeAll": True,
            "allValue": ".*",
            "multi": True,
            "name": "supervisor_title",
            "options": [],
            "query": SUPERVISOR_VARIABLE_QUERY,
            "refresh": 2,
            "regex": '/display="(?<text>[^"]+)".*supervisor="(?<value>[^"]+)"/',
            "sort": 1,
            "type": "query",
        },
        # Скрытая: по ней повторяются таблицы батчей, текст — заголовок таблицы
        {
            "current": {"text": ["All"], "value": ["$__all"]},
            "datasource": DS,
            "definition": BATCH_VARIABLE_QUERY,
            "hide": 2,
            "includeAll": True,
            "allValue": ".*",
            "multi": True,
            "name": "batch_title",
            "options": [],
            "query": BATCH_VARIABLE_QUERY,
            "refresh": 2,
            "regex": '/batch="(?<value>[^"]+)".*display="(?<text>[^"]+)"/',
            "sort": 1,
            "type": "query",
        },
        # Скрытая: адрес Horizon этого окружения для ссылки «Horizon», его отдаёт приложение. За неделю до конца
        # периода: переменная считается на конец периода, а в прошлом на этот момент метрики могло не быть. Не в URL:
        # ссылка на дашборд, открытая в другом окружении, вела бы в чужой Horizon
        {
            "current": {},
            "datasource": DS,
            "definition": "query_result(group by (url) (last_over_time(office_horizon_info[7d])))",
            "hide": 2,
            "includeAll": False,
            "multi": False,
            "name": "horizon_url",
            "options": [],
            "query": "query_result(group by (url) (last_over_time(office_horizon_info[7d])))",
            "refresh": 1,
            "regex": '/url="([^"]+)"/',
            "skipUrlSync": True,
            "type": "query",
        },
        # Скрытая: адрес сервиса синтеза речи для фраз об алертах (HPG_TTS_URL), его отдаёт приложение, как
        # horizon_url; без адреса — только звуковой сигнал
        {
            "current": {},
            "datasource": DS,
            "definition": "query_result(group by (tts_url) (last_over_time(office_horizon_info[7d])))",
            "hide": 2,
            "includeAll": False,
            "multi": False,
            "name": "tts_url",
            "options": [],
            "query": "query_result(group by (tts_url) (last_over_time(office_horizon_info[7d])))",
            "refresh": 1,
            "regex": '/tts_url="([^"]+)"/',
            "skipUrlSync": True,
            "type": "query",
        },
        # Скрытая: источник Prometheus по типу, а не по uid — дашборд импортируется в любую Grafana без правок
        {
            "current": {},
            "hide": 2,
            "includeAll": False,
            "multi": False,
            "name": "datasource",
            "options": [],
            "query": "prometheus",
            "refresh": 1,
            "regex": "",
            "skipUrlSync": True,
            "type": "datasource",
        },
        # Скрытая: источник Infinity с JSON приложения для Completed Jobs, Failed Jobs и Pending Jobs. По типу, а не по uid: в рабочей
        # Grafana источник создаётся из UI, и uid у него свой
        {
            "current": {},
            "hide": 2,
            "includeAll": False,
            "multi": False,
            "name": "app_api",
            "options": [],
            "query": "yesoreyeram-infinity-datasource",
            "refresh": 1,
            "regex": "",
            "skipUrlSync": True,
            "type": "datasource",
        },
    ]},
    "time": {"from": "now-1h", "to": "now"},
    # Реже 5 минут обновлять смысла нет: на дашборде текущее состояние очередей
    "timepicker": {"refresh_intervals": ["5s", "10s", "15s", "30s", "1m", "5m"]},
    "timezone": "browser",
    "title": "Queues",
    "uid": "horizon",
    "version": 1,
}

# Порядок фильтров: Supervisor и Batch задают, что смотреть, Queue и Job сужаются под них
dashboard["templating"]["list"].sort(key=lambda variable: ["datasource", *FILTER_ORDER, "horizon_url", "tts_url", "app_api"].index(variable["name"]))

text = json.dumps(dashboard, ensure_ascii=False, indent=2) + "\n"
# Запросы написаны с префиксом office_: при другом префиксе приложения он меняется во всём дашборде
if OPTIONS.prefix != "office":
    text = re.sub(r"\boffice_(?=(?:queue|horizon)_)", OPTIONS.prefix + "_", text)
with open(OPTIONS.output, "w", encoding="utf-8") as f:
    f.write(text)
