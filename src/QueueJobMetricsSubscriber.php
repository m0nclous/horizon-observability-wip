<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Снимает метрики попыток выполнения джобов в процессах воркеров.
 *
 * Попытка начинается с JobProcessing и завершается ровно одним событием:
 * JobAttempted (в т.ч. после исключения) либо JobTimedOut — после него процесс
 * воркера убивается и JobAttempted уже не приходит.
 *
 * Sync-джобы пропускаем: SyncQueue шлёт JobProcessing, но не шлёт JobAttempted,
 * и в долгоживущем процессе (Octane) их попытки копились бы в памяти.
 *
 * JobQueued приходит и в веб-запросах: на него только одна запись в Redis и только
 * для джобов, отложенных при отправке, и для джобов батча (связь вида батча с классом).
 */
class QueueJobMetricsSubscriber
{
    // Связь «вид батча, очередь, класс» обновляется не чаще раза в час
    private const BATCH_JOB_CLASS_REFRESH_SECONDS = 3600;
    // Памятки по батчам сбрасываются, если разрослись: процессы воркеров и Octane живут долго
    private const BATCH_MEMO_LIMIT = 1000;

    /**
     * Текущие попытки по spl_object_id джоба: воркер передаёт во все события попытки
     * один и тот же объект. startedAt — hrtime в нс, null — handle() не вызывался.
     *
     * @var array<int, array{startedAt: ?int, waitSeconds: ?float}>
     */
    private array $attempts = [];

    /**
     * Вид батча по id: имя батча читается из БД один раз на батч. Статические, а не в экземпляре:
     * подписчик может создаваться заново, а батч отправляется сотнями джобов подряд.
     *
     * @var array<string, string>
     */
    private static array $batchKinds = [];

    /**
     * Когда связь «вид, очередь, класс» записана последний раз: чаще раза в час её не обновляем.
     *
     * @var array<string, int>
     */
    private static array $batchJobClassesWrittenAt = [];

    public function __construct(private readonly QueueJobMetricsStorage $storage)
    {
    }

    public function subscribe(Dispatcher $events): void
    {
        if (!config('horizon-prometheus-grafana.enabled')) {
            return;
        }

        // Слушаем методами этого экземпляра, а не по имени класса: иначе на каждое
        // событие контейнер создаст новый объект и состояние попытки потеряется
        $events->listen(JobQueued::class, [$this, 'handleJobQueued']);
        $events->listen(JobProcessing::class, [$this, 'handleJobProcessing']);
        $events->listen(JobExceptionOccurred::class, [$this, 'handleJobExceptionOccurred']);
        $events->listen(JobAttempted::class, [$this, 'handleJobAttempted']);
        $events->listen(JobTimedOut::class, [$this, 'handleJobTimedOut']);
    }

    /**
     * Horizon не кладёт delay в payload (RedisQueue::later), поэтому момент, когда отложенный
     * джоб станет доступен, запоминается отдельно. У других драйверов delay есть в payload.
     */
    public function handleJobQueued(JobQueued $event): void
    {
        $this->rememberBatchJobClass($event);

        $delay = (int) $event->delay;
        $driver = config("queue.connections.{$event->connectionName}.driver");

        if ($delay <= 0 || $driver !== 'redis' || (string) $event->id === '') {
            return;
        }

        try {
            $this->storage->rememberReadyAt((string) $event->id, $delay);
        } catch (Throwable $exception) {
            // Сбой метрик не должен мешать отправке джоба
            Log::warning('QueueMetrics: не удалось запомнить время доступности отложенного джоба', [
                'job' => is_string($event->job) ? $event->job : get_debug_type($event->job),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    public function handleJobProcessing(JobProcessing $event): void
    {
        if ($event->job instanceof SyncJob) {
            return;
        }

        $this->attempts[spl_object_id($event->job)] = [
            'startedAt' => hrtime(true),
            'waitSeconds' => $this->waitSeconds($event->job),
        ];
    }

    /**
     * Worker проверяет число попыток до запуска джоба. Если они кончились, джоб
     * проваливается с MaxAttemptsExceededException без вызова handle(), и его
     * «длительность» в несколько миллисекунд только исказила бы гистограмму.
     */
    public function handleJobExceptionOccurred(JobExceptionOccurred $event): void
    {
        $id = spl_object_id($event->job);

        if ($event->exception instanceof MaxAttemptsExceededException && isset($this->attempts[$id])) {
            $this->attempts[$id]['startedAt'] = null;
        }
    }

    public function handleJobAttempted(JobAttempted $event): void
    {
        $this->finish($event->job, $this->resolveStatus($event));
    }

    /**
     * Приходит из обработчика SIGALRM, когда джоб мог быть посреди своей команды Redis.
     * Если запись столкнётся с ней, ошибка гасится в finish() и теряется только этот
     * замер: воркер всё равно сразу убивается.
     */
    public function handleJobTimedOut(JobTimedOut $event): void
    {
        $this->finish($event->job, JobAttemptStatus::TIMED_OUT);
    }

    private function finish(Job $job, JobAttemptStatus $status): void
    {
        $id = spl_object_id($job);
        $attempt = $this->attempts[$id] ?? null;
        unset($this->attempts[$id]);

        $startedAt = $attempt['startedAt'] ?? null;

        try {
            $this->storage->recordAttempt(
                connection: (string) $job->getConnectionName(),
                queue: (string) $job->getQueue(),
                job: (string) $job->resolveName(),
                status: $status,
                durationSeconds: $startedAt === null ? null : $this->durationSeconds($job, $status, $startedAt),
                waitSeconds: $attempt['waitSeconds'] ?? null,
            );
        } catch (Throwable $exception) {
            // Сбой метрик не должен влиять на обработку джоба
            Log::warning('QueueMetrics: не удалось записать метрики джоба', [
                'job' => $job->resolveName(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveStatus(JobAttempted $event): JobAttemptStatus
    {
        $job = $event->job;

        return match (true) {
            $job->hasFailed() => JobAttemptStatus::FAILED,
            $job->isReleased() && $event->exceptionOccurred => JobAttemptStatus::RETRIED,
            $job->isReleased() => JobAttemptStatus::RELEASED,
            default => JobAttemptStatus::COMPLETED,
        };
    }

    /**
     * По таймауту обработчик SIGALRM ещё до JobTimedOut проваливает джоб: удаление, failed()
     * джоба, запись в failed_jobs. Таймер воркер заводит до JobProcessing, поэтому сама
     * попытка не дольше timeout джоба. Если timeout задан только у супервизора (--timeout),
     * в событиях его нет, и длительность включает обработку провала.
     *
     * @param int $startedAt hrtime в нс
     */
    private function durationSeconds(Job $job, JobAttemptStatus $status, int $startedAt): float
    {
        $elapsed = (hrtime(true) - $startedAt) / 1e9;
        $timeout = $status === JobAttemptStatus::TIMED_OUT ? $job->timeout() : null;

        return is_numeric($timeout) && $timeout > 0 ? min($elapsed, (float) $timeout) : $elapsed;
    }

    /**
     * Сколько первая попытка ждала свободного воркера: от момента, когда джоб стал доступен,
     * до старта. Момент доступности — pushedAt от Horizon (или createdAt) плюс delay из payload,
     * а для отложенных при отправке через Horizon — время из handleJobQueued: delay в его payload
     * всегда null. Это время читается из Redis, только если ожидание без него не меньше секунды.
     * Для повторных попыток в payload нет момента возврата в очередь — их не считаем.
     */
    private function waitSeconds(Job $job): ?float
    {
        if ($job->attempts() !== 1) {
            return null;
        }

        $payload = $job->payload();
        $queuedAt = $payload['pushedAt'] ?? $payload['createdAt'] ?? null;

        if (!is_numeric($queuedAt)) {
            return null;
        }

        $now = microtime(true);
        $availableAt = (float) $queuedAt + (float) ($payload['delay'] ?? 0);

        if ($now - $availableAt >= 1.0) {
            $availableAt = max($availableAt, (float) ($this->readyAt($job) ?? 0));
        }

        return max(0.0, $now - $availableAt);
    }

    private function readyAt(Job $job): ?int
    {
        $id = (string) $job->getJobId();

        if ($id === '') {
            return null;
        }

        try {
            return $this->storage->pullReadyAt($id);
        } catch (Throwable $exception) {
            // Сбой метрик не должен влиять на обработку джоба
            Log::warning('QueueMetrics: не удалось прочитать время доступности отложенного джоба', [
                'job' => $job->resolveName(),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Джоб из батча: запоминает, что в батчах его вида есть джобы этого класса в этой очереди и соединении.
     * Класс — displayName из payload, как метка job_class у остальных метрик.
     */
    private function rememberBatchJobClass(JobQueued $event): void
    {
        $batchId = is_object($event->job) && isset($event->job->batchId) ? (string) $event->job->batchId : '';

        if ($batchId === '') {
            return;
        }

        try {
            if (count(self::$batchKinds) > self::BATCH_MEMO_LIMIT) {
                self::$batchKinds = [];
                self::$batchJobClassesWrittenAt = [];
            }

            self::$batchKinds[$batchId] ??= BatchKind::of(Bus::findBatch($batchId)?->name);
            $queue = (string) ($event->queue ?: config("queue.connections.{$event->connectionName}.queue"));
            $job = (string) ($event->payload()['displayName'] ?? get_debug_type($event->job));
            $key = self::$batchKinds[$batchId] . "\0{$event->connectionName}\0{$queue}\0{$job}";

            if (time() - (self::$batchJobClassesWrittenAt[$key] ?? 0) < self::BATCH_JOB_CLASS_REFRESH_SECONDS) {
                return;
            }

            $this->storage->rememberBatchJobClass(self::$batchKinds[$batchId], (string) $event->connectionName, $queue, $job);
            self::$batchJobClassesWrittenAt[$key] = time();
        } catch (Throwable $exception) {
            // Сбой метрик не должен мешать отправке джоба
            Log::warning('QueueMetrics: не удалось запомнить класс джоба батча', [
                'batch' => $batchId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
