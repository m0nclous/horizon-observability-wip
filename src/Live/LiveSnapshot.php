<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live;

use UnexpectedValueException;

/**
 * The live snapshot, JSON schema v1 (Addendum R4.4; public and SemVer-covered as JSON, the class is not).
 *
 * {@see self::compose()} derives every level, colour and caption from raw measurements with {@see Captions}, so poll
 * and stream modes read alike; {@see self::toArray()} is the public JSON and {@see self::fromArray()} reads it back
 * (snapshot cache). {@see self::tiles()} are the rows pushed in stream mode, so poll and stream panels share their
 * transformations.
 *
 * Partial failures (one queue) keep the snapshot and list the source in `errors`; the failed queue is left out of
 * the sums. {@see self::unavailable()} is the snapshot of a failed build: null values, caption `Live unavailable`,
 * colour `text`.
 *
 * @phpstan-type ScopePart array{supervisors: list<string>|null, queues: list<string>, job_filter: bool}
 * @phpstan-type HorizonPart array{status: int|null, level: int, color: string, caption: string}
 * @phpstan-type PendingPart array{value: int|null, oldest_seconds: int, oldest_queue: string|null, wait_ratio: float|null, level: int, color: string, caption: string}
 * @phpstan-type DelayedPart array{value: int|null, due: int|null}
 * @phpstan-type ReservedPart array{value: int|null}
 * @phpstan-type ThroughputPart array{window_seconds: int, bucket_seconds: int, completed: int|null, failed: int|null, retried: int|null, completed_per_minute: int|null, failed_per_minute: float|null, caption: string}
 * @phpstan-type SupervisorRow array{name: string, display: string, connection: string, balance: string, processes: int, min_processes: int, max_processes: int, busy: int, utilization: float, level: int, color: string, caption: string, queues: list<string>}
 * @phpstan-type QueueRow array{connection: string, queue: string, pending: int, reserved: int, delayed: int, delayed_due: int, oldest_seconds: int, wait_threshold_seconds: int|null, wait_ratio: float|null, level: int, color: string, completed_per_minute: int|null, failed_per_minute: float|null, supervisors: list<string>}
 * @phpstan-type TileRow array{tile: string, key: string, caption: string, value: float|null, color: string, level: int}
 * @phpstan-type ErrorRow array{source: string, message: string}
 * @phpstan-type SupervisorInput array{name: string, connection: string, balance: string, processes: int, min_processes: int, max_processes: int, busy: int, queues: list<string>}
 * @phpstan-type QueueInput array{connection: string, queue: string, pending: int, reserved: int, delayed: int, delayed_due: int, oldest_seconds: int, wait_threshold_seconds: int|null, completed_per_minute: int|null, failed_per_minute: float|null, supervisors: list<string>}
 * @phpstan-type ThroughputInput array{completed: int, failed: int, retried: int}
 *
 * @internal
 */
final readonly class LiveSnapshot
{
    public const SCHEMA_VERSION = 1;

    /**
     * Ring window and bucket width of the throughput counts (Addendum R4.5).
     */
    public const WINDOW_SECONDS = 60;

    public const BUCKET_SECONDS = 5;

    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * @param  int  $generatedAt  Unix ms when the build started
     * @param  int  $ageMs  cache age at response time (informational)
     * @param  int  $ttlMs  the cache TTL
     * @param  ScopePart  $scope
     * @param  HorizonPart  $horizon
     * @param  PendingPart  $pending
     * @param  DelayedPart  $delayed
     * @param  ReservedPart  $reserved
     * @param  ThroughputPart  $throughput
     * @param  list<SupervisorRow>  $supervisors  sorted by display name
     * @param  list<QueueRow>  $queues
     * @param  list<ErrorRow>  $errors
     */
    public function __construct(
        public int $generatedAt,
        public int $ageMs,
        public int $ttlMs,
        public array $scope,
        public array $horizon,
        public array $pending,
        public array $delayed,
        public array $reserved,
        public array $throughput,
        public array $supervisors,
        public array $queues,
        public array $errors,
    ) {}

    /**
     * Build a snapshot from raw measurements and derive levels, colours and captions (Addendum R4.2 step 5, R4.4).
     *
     * @param  int|null  $horizonStatus  1, 0 or -1; null when Horizon's state could not be read
     * @param  list<SupervisorInput>  $supervisors  `processes` as Horizon reports them; `busy` = reserved jobs of the
     *                                              supervisor's queues (of the selected classes with a Job filter)
     * @param  list<QueueInput>  $queues  queues read successfully; `pending` = ready jobs (`LLEN` + due delayed),
     *                                    `delayed` = not yet due, `oldest_seconds` = 0 when nothing waits
     * @param  ThroughputInput|null  $throughput  ring counts over the 60 s window (failed = failed + timed_out); null
     *                                            without ring data
     * @param  list<ErrorRow>  $errors
     */
    public static function compose(
        LiveScope $scope,
        int $generatedAtMs,
        int $ttlMs,
        ?int $horizonStatus,
        array $supervisors,
        array $queues,
        ?array $throughput,
        array $errors = [],
    ): self {
        $running = $horizonStatus === 1;

        $supervisorRows = [];

        foreach ($supervisors as $supervisor) {
            $display = Captions::display($supervisor['name']);
            $max = $supervisor['max_processes'];
            $utilization = $max > 0 ? min(1.0, $supervisor['busy'] / $max) : 0.0;
            $level = Captions::utilizationLevel($utilization, $running);

            $supervisorRows[] = [
                'name' => $supervisor['name'],
                'display' => $display,
                'connection' => $supervisor['connection'],
                'balance' => $supervisor['balance'],
                'processes' => $running ? $supervisor['processes'] : 0,
                'min_processes' => $supervisor['min_processes'],
                'max_processes' => $max,
                'busy' => $supervisor['busy'],
                'utilization' => round($utilization, 3),
                'level' => $level,
                'color' => Captions::color($level),
                'caption' => Captions::supervisor($display, $max),
                'queues' => $supervisor['queues'],
            ];
        }

        usort($supervisorRows, static fn (array $a, array $b): int => [$a['display'], $a['name']] <=> [$b['display'], $b['name']]);

        $queueRows = [];
        $worst = null;
        $worstRatio = null;

        foreach ($queues as $queue) {
            $threshold = $queue['wait_threshold_seconds'];
            $ratio = $threshold !== null && $threshold > 0 ? $queue['oldest_seconds'] / $threshold : null;
            $level = Captions::waitLevel($ratio);

            $queueRows[] = [
                'connection' => $queue['connection'],
                'queue' => $queue['queue'],
                'pending' => $queue['pending'],
                'reserved' => $queue['reserved'],
                'delayed' => $queue['delayed'],
                'delayed_due' => $queue['delayed_due'],
                'oldest_seconds' => $queue['oldest_seconds'],
                'wait_threshold_seconds' => $threshold,
                'wait_ratio' => $ratio === null ? null : round($ratio, 2),
                'level' => $level,
                'color' => Captions::color($level),
                'completed_per_minute' => $queue['completed_per_minute'],
                'failed_per_minute' => $queue['failed_per_minute'],
                'supervisors' => $queue['supervisors'],
            ];

            if ($queue['oldest_seconds'] > 0 && ($worst === null || self::worse($queue, $ratio, $worst, $worstRatio))) {
                $worst = $queue;
                $worstRatio = $ratio;
            }
        }

        if ($scope->jobFilter || $worst === null) {
            $pending = [
                'value' => $scope->jobFilter ? null : array_sum(array_column($queues, 'pending')),
                'oldest_seconds' => 0,
                'oldest_queue' => null,
                'wait_ratio' => null,
                'level' => 0,
                'color' => Captions::GREEN,
                'caption' => Captions::oldest(null, null),
            ];
        } else {
            $level = Captions::waitLevel($worstRatio);
            $pending = [
                'value' => array_sum(array_column($queues, 'pending')),
                'oldest_seconds' => $worst['oldest_seconds'],
                'oldest_queue' => $worst['queue'],
                'wait_ratio' => $worstRatio === null ? null : round($worstRatio, 2),
                'level' => $level,
                'color' => Captions::color($level),
                'caption' => Captions::oldest($worst['oldest_seconds'], $worst['queue']),
            ];
        }

        $completedPerMinute = $throughput === null ? null : (int) round($throughput['completed'] * 60 / self::WINDOW_SECONDS);

        if ($horizonStatus === null) {
            $horizon = ['status' => null, 'level' => 0, 'color' => Captions::TEXT, 'caption' => Captions::UNAVAILABLE];
        } else {
            $horizonLevel = Captions::horizonLevel($horizonStatus);
            $horizon = ['status' => $horizonStatus, 'level' => $horizonLevel, 'color' => Captions::color($horizonLevel), 'caption' => Captions::horizon($horizonStatus)];
        }

        return new self(
            generatedAt: $generatedAtMs,
            ageMs: 0,
            ttlMs: $ttlMs,
            scope: self::scopePart($scope),
            horizon: $horizon,
            pending: $pending,
            delayed: $scope->jobFilter
                ? ['value' => null, 'due' => null]
                : ['value' => array_sum(array_column($queues, 'delayed')), 'due' => array_sum(array_column($queues, 'delayed_due'))],
            reserved: ['value' => array_sum(array_column($queues, 'reserved'))],
            throughput: [
                'window_seconds' => self::WINDOW_SECONDS,
                'bucket_seconds' => self::BUCKET_SECONDS,
                'completed' => $throughput['completed'] ?? null,
                'failed' => $throughput['failed'] ?? null,
                'retried' => $throughput['retried'] ?? null,
                'completed_per_minute' => $completedPerMinute,
                'failed_per_minute' => $throughput === null ? null : round($throughput['failed'] * 60 / self::WINDOW_SECONDS, 2),
                'caption' => Captions::rpm($completedPerMinute),
            ],
            supervisors: $supervisorRows,
            queues: $queueRows,
            errors: $errors,
        );
    }

    /**
     * A snapshot without data: no supervisor, no queue, zero counts, Horizon stopped, no ring data.
     */
    public static function empty(LiveScope $scope, int $generatedAtMs, int $ttlMs): self
    {
        return self::compose($scope, $generatedAtMs, $ttlMs, -1, [], [], null);
    }

    /**
     * The snapshot of a failed build (Addendum R4.10): null values, caption `Live unavailable`, colour `text`.
     *
     * @param  list<ErrorRow>  $errors
     */
    public static function unavailable(LiveScope $scope, int $generatedAtMs, int $ttlMs, array $errors): self
    {
        return new self(
            generatedAt: $generatedAtMs,
            ageMs: 0,
            ttlMs: $ttlMs,
            scope: self::scopePart($scope),
            horizon: ['status' => null, 'level' => 0, 'color' => Captions::TEXT, 'caption' => Captions::UNAVAILABLE],
            pending: ['value' => null, 'oldest_seconds' => 0, 'oldest_queue' => null, 'wait_ratio' => null, 'level' => 0, 'color' => Captions::TEXT, 'caption' => Captions::UNAVAILABLE],
            delayed: ['value' => null, 'due' => null],
            reserved: ['value' => null],
            throughput: [
                'window_seconds' => self::WINDOW_SECONDS,
                'bucket_seconds' => self::BUCKET_SECONDS,
                'completed' => null,
                'failed' => null,
                'retried' => null,
                'completed_per_minute' => null,
                'failed_per_minute' => null,
                'caption' => Captions::UNAVAILABLE,
            ],
            supervisors: [],
            queues: [],
            errors: $errors,
        );
    }

    /**
     * The same snapshot with the queue part unknown: no queue in scope could be read, so Pending, Delayed and
     * Reserved are null with caption `Live unavailable` and colour `text` (Addendum R4.10), and the supervisors keep
     * their processes but lose the utilisation colour (busy workers are unknown; `text`, level 0) unless Horizon is
     * not running. The Horizon state and the throughput stay as read.
     */
    public function withQueuesUnavailable(): self
    {
        $running = $this->horizon['status'] === 1;
        $supervisors = array_map(static fn (array $row): array => $running
            ? [...$row, 'busy' => 0, 'utilization' => 0.0, 'level' => 0, 'color' => Captions::TEXT]
            : $row, $this->supervisors);

        return new self(
            $this->generatedAt,
            $this->ageMs,
            $this->ttlMs,
            $this->scope,
            $this->horizon,
            ['value' => null, 'oldest_seconds' => 0, 'oldest_queue' => null, 'wait_ratio' => null, 'level' => 0, 'color' => Captions::TEXT, 'caption' => Captions::UNAVAILABLE],
            ['value' => null, 'due' => null],
            ['value' => null],
            $this->throughput,
            $supervisors,
            [],
            $this->errors,
        );
    }

    /**
     * The same snapshot with the cache age at response time.
     */
    public function withAge(int $ageMs): self
    {
        return new self(
            $this->generatedAt,
            max(0, $ageMs),
            $this->ttlMs,
            $this->scope,
            $this->horizon,
            $this->pending,
            $this->delayed,
            $this->reserved,
            $this->throughput,
            $this->supervisors,
            $this->queues,
            $this->errors,
        );
    }

    public function generatedAtMs(): int
    {
        return $this->generatedAt;
    }

    /**
     * The public JSON document, schema v1.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'v' => self::SCHEMA_VERSION,
            'generated_at' => $this->generatedAt,
            'age_ms' => $this->ageMs,
            'ttl_ms' => $this->ttlMs,
            'scope' => $this->scope,
            'horizon' => $this->horizon,
            'pending' => $this->pending,
            'delayed' => $this->delayed,
            'reserved' => $this->reserved,
            'throughput' => $this->throughput,
            'supervisors' => $this->supervisors,
            'queues' => $this->queues,
            'tiles' => $this->tiles(),
            'errors' => $this->errors,
        ];
    }

    /**
     * {@see self::toArray()} as JSON (floats keep their fraction, `1.0`).
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), self::JSON_FLAGS);
    }

    /**
     * Whether the snapshot carries live data: false for {@see self::unavailable()} and for any snapshot whose
     * Horizon state and queue state are both unknown (caption `Live unavailable` on the Horizon and Pending parts).
     * The snapshot cache stores only available snapshots, so the next request after a failed build tries again.
     */
    public function isAvailable(): bool
    {
        return $this->horizon['status'] !== null || $this->pending['caption'] !== Captions::UNAVAILABLE;
    }

    /**
     * The tile rows in push order (Addendum R4.7): pending, delayed, supervisors by display name, throughput. A
     * supervisor tile shows the running processes, or with a Job or Batch filter the busy workers of the selected
     * classes.
     *
     * @return list<array{tile: string, key: string, caption: string, value: float|null, color: string, level: int}>
     */
    public function tiles(): array
    {
        $tiles = [
            $this->tile('pending', '', $this->pending['caption'], $this->pending['value'], $this->pending['color'], $this->pending['level']),
            $this->tile('delayed', '', '', $this->delayed['value'], Captions::TEXT, 0),
        ];

        foreach ($this->supervisors as $supervisor) {
            // With a Job or Batch filter the tile counts the workers busy with the selected classes, as the off-mode
            // tile does (Addendum R4.1); like the processes, they read 0 while Horizon is not running.
            $value = $this->scope['job_filter'] ? ($this->horizon['status'] === 1 ? $supervisor['busy'] : 0) : $supervisor['processes'];
            $tiles[] = $this->tile('supervisor', $supervisor['name'], $supervisor['caption'], $value, $supervisor['color'], $supervisor['level']);
        }

        $tiles[] = $this->tile('throughput', '', $this->throughput['caption'], $this->throughput['completed_per_minute'], Captions::TEXT, 0);

        return $tiles;
    }

    /**
     * Read a {@see self::toArray()} document back (`tiles` are derived and ignored).
     *
     * @param  array<array-key, mixed>  $data
     *
     * @throws UnexpectedValueException when the document is not schema v1
     */
    public static function fromArray(array $data): self
    {
        if (($data['v'] ?? null) !== self::SCHEMA_VERSION) {
            throw new UnexpectedValueException('Not a live snapshot of schema v1.');
        }

        $scope = self::map($data, 'scope');
        $horizon = self::map($data, 'horizon');
        $pending = self::map($data, 'pending');
        $delayed = self::map($data, 'delayed');
        $reserved = self::map($data, 'reserved');
        $throughput = self::map($data, 'throughput');

        $supervisors = [];

        foreach (self::rows($data, 'supervisors') as $row) {
            $supervisors[] = [
                'name' => self::string($row, 'name'),
                'display' => self::string($row, 'display'),
                'connection' => self::string($row, 'connection'),
                'balance' => self::string($row, 'balance'),
                'processes' => self::int($row, 'processes'),
                'min_processes' => self::int($row, 'min_processes'),
                'max_processes' => self::int($row, 'max_processes'),
                'busy' => self::int($row, 'busy'),
                'utilization' => self::float($row, 'utilization'),
                'level' => self::int($row, 'level'),
                'color' => self::string($row, 'color'),
                'caption' => self::string($row, 'caption'),
                'queues' => self::strings($row, 'queues'),
            ];
        }

        $queues = [];

        foreach (self::rows($data, 'queues') as $row) {
            $queues[] = [
                'connection' => self::string($row, 'connection'),
                'queue' => self::string($row, 'queue'),
                'pending' => self::int($row, 'pending'),
                'reserved' => self::int($row, 'reserved'),
                'delayed' => self::int($row, 'delayed'),
                'delayed_due' => self::int($row, 'delayed_due'),
                'oldest_seconds' => self::int($row, 'oldest_seconds'),
                'wait_threshold_seconds' => self::nullableInt($row, 'wait_threshold_seconds'),
                'wait_ratio' => self::nullableFloat($row, 'wait_ratio'),
                'level' => self::int($row, 'level'),
                'color' => self::string($row, 'color'),
                'completed_per_minute' => self::nullableInt($row, 'completed_per_minute'),
                'failed_per_minute' => self::nullableFloat($row, 'failed_per_minute'),
                'supervisors' => self::strings($row, 'supervisors'),
            ];
        }

        $errors = [];

        foreach (self::rows($data, 'errors') as $row) {
            $errors[] = ['source' => self::string($row, 'source'), 'message' => self::string($row, 'message')];
        }

        return new self(
            generatedAt: self::int($data, 'generated_at'),
            ageMs: self::int($data, 'age_ms'),
            ttlMs: self::int($data, 'ttl_ms'),
            scope: [
                'supervisors' => ($scope['supervisors'] ?? null) === null ? null : self::strings($scope, 'supervisors'),
                'queues' => self::strings($scope, 'queues'),
                'job_filter' => self::bool($scope, 'job_filter'),
            ],
            horizon: [
                'status' => self::nullableInt($horizon, 'status'),
                'level' => self::int($horizon, 'level'),
                'color' => self::string($horizon, 'color'),
                'caption' => self::string($horizon, 'caption'),
            ],
            pending: [
                'value' => self::nullableInt($pending, 'value'),
                'oldest_seconds' => self::int($pending, 'oldest_seconds'),
                'oldest_queue' => self::nullableString($pending, 'oldest_queue'),
                'wait_ratio' => self::nullableFloat($pending, 'wait_ratio'),
                'level' => self::int($pending, 'level'),
                'color' => self::string($pending, 'color'),
                'caption' => self::string($pending, 'caption'),
            ],
            delayed: ['value' => self::nullableInt($delayed, 'value'), 'due' => self::nullableInt($delayed, 'due')],
            reserved: ['value' => self::nullableInt($reserved, 'value')],
            throughput: [
                'window_seconds' => self::int($throughput, 'window_seconds'),
                'bucket_seconds' => self::int($throughput, 'bucket_seconds'),
                'completed' => self::nullableInt($throughput, 'completed'),
                'failed' => self::nullableInt($throughput, 'failed'),
                'retried' => self::nullableInt($throughput, 'retried'),
                'completed_per_minute' => self::nullableInt($throughput, 'completed_per_minute'),
                'failed_per_minute' => self::nullableFloat($throughput, 'failed_per_minute'),
                'caption' => self::string($throughput, 'caption'),
            ],
            supervisors: $supervisors,
            queues: $queues,
            errors: $errors,
        );
    }

    /**
     * @return ScopePart
     */
    private static function scopePart(LiveScope $scope): array
    {
        return ['supervisors' => $scope->supervisors, 'queues' => $scope->queueNames(), 'job_filter' => $scope->jobFilter];
    }

    /**
     * Whether queue $a waits worse than queue $b: higher wait ratio (a queue without a threshold ranks below any
     * ratio), then the older age, then the name.
     *
     * @param  QueueInput  $a
     * @param  QueueInput  $b
     */
    private static function worse(array $a, ?float $ratioA, array $b, ?float $ratioB): bool
    {
        $ratioA ??= -1.0;
        $ratioB ??= -1.0;

        if ($ratioA !== $ratioB) {
            return $ratioA > $ratioB;
        }

        if ($a['oldest_seconds'] !== $b['oldest_seconds']) {
            return $a['oldest_seconds'] > $b['oldest_seconds'];
        }

        return strcmp($a['queue'], $b['queue']) < 0;
    }

    /**
     * @return array{tile: string, key: string, caption: string, value: float|null, color: string, level: int}
     */
    private function tile(string $tile, string $key, string $caption, int|float|null $value, string $color, int $level): array
    {
        return ['tile' => $tile, 'key' => $key, 'caption' => $caption, 'value' => $value === null ? null : (float) $value, 'color' => $color, 'level' => $level];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function map(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw new UnexpectedValueException(sprintf('Live snapshot field "%s" must be an object.', $key));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<array<array-key, mixed>>
     */
    private static function rows(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnexpectedValueException(sprintf('Live snapshot field "%s" must be a list.', $key));
        }

        $rows = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                throw new UnexpectedValueException(sprintf('Live snapshot field "%s" must be a list of objects.', $key));
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function int(array $data, string $key): int
    {
        return self::nullableInt($data, $key) ?? throw new UnexpectedValueException(sprintf('Live snapshot field "%s" must be an integer.', $key));
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if ($value !== null && ! is_int($value)) {
            throw new UnexpectedValueException(sprintf('Live snapshot field "%s" must be an integer or null.', $key));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function float(array $data, string $key): float
    {
        return self::nullableFloat($data, $key) ?? throw new UnexpectedValueException(sprintf('Live snapshot field "%s" must be a number.', $key));
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function nullableFloat(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        if ($value !== null && ! is_int($value) && ! is_float($value)) {
            throw new UnexpectedValueException(sprintf('Live snapshot field "%s" must be a number or null.', $key));
        }

        return $value === null ? null : (float) $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        return self::nullableString($data, $key) ?? throw new UnexpectedValueException(sprintf('Live snapshot field "%s" must be a string.', $key));
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw new UnexpectedValueException(sprintf('Live snapshot field "%s" must be a string or null.', $key));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        if (! is_bool($value)) {
            throw new UnexpectedValueException(sprintf('Live snapshot field "%s" must be a boolean.', $key));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnexpectedValueException(sprintf('Live snapshot field "%s" must be a list of strings.', $key));
        }

        $strings = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new UnexpectedValueException(sprintf('Live snapshot field "%s" must be a list of strings.', $key));
            }

            $strings[] = $item;
        }

        return $strings;
    }
}
