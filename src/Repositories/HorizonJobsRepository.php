<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

/**
 * Ожидающие, выполненные и упавшие джобы из Redis Horizon — те же, что на его страницах Pending Jobs,
 * Completed Jobs и Failed Jobs: sorted set pending_jobs, completed_jobs или failed_jobs (id, score — время
 * постановки в очередь, завершения или падения со знаком минус, поэтому новые первыми) и hash джоба по id.
 * Ожидающие — и уже взятые воркером, пока не выполнены; Horizon хранит их horizon.trim.pending
 * минут, выполненные — horizon.trim.completed, упавшие — horizon.trim.failed; заглушённые в выполненные не попадают.
 *
 * Время постановки в очередь — created_at, его Horizon пишет при отправке джоба. Payload читается только
 * у упавших — ради числа попыток: он тяжёлый, а упавших намного меньше. Исключение упавшего со стеком весит
 * килобайты, поэтому из него читается только первая строка и только у джобов, попавших в список.
 */
class HorizonJobsRepository
{
    private const PENDING_FIELDS = ['id', 'name', 'queue', 'created_at'];
    private const COMPLETED_FIELDS = ['id', 'name', 'queue', 'created_at', 'reserved_at', 'completed_at'];
    private const FAILED_FIELDS = ['id', 'name', 'queue', 'reserved_at', 'failed_at', 'payload'];
    private const CHUNK = 500;

    // Сколько последних джобов периода просматривать с фильтрами: редкий класс старше этого в список не попадёт
    private const SCAN_LIMIT = 10000;

    // Первые строки исключений джобов KEYS, не длиннее ARGV[1] байт; нет исключения — false
    private const EXCEPTION_SCRIPT = <<<'LUA'
        local lines = {}
        for index, key in ipairs(KEYS) do
            local exception = redis.call('HGET', key, 'exception')
            lines[index] = exception and string.sub(string.match(exception, '^[^\n]*'), 1, tonumber(ARGV[1])) or false
        end
        return lines
        LUA;

    // Скрипт копирует исключения целиком и держит Redis, поэтому джобов на один вызов немного
    private const EXCEPTION_CHUNK = 100;
    private const EXCEPTION_MAX_BYTES = 1000;

    /**
     * Ожидающие джобы, поставленные в очередь за период, новые первыми — как у Horizon.
     *
     * @param list<string>|null $queues null — все
     * @param list<string>|null $jobs null — все
     * @param float|null $from Начало периода, unix-время; null — без границы
     * @param float|null $to Конец периода
     * @return Collection<int, array{id: string, name: string, queue: string, queued_at: float}>
     */
    public function getPending(?array $queues, ?array $jobs, ?float $from, ?float $to, int $limit): Collection
    {
        return $this->scan('pending_jobs', self::PENDING_FIELDS, 'created_at', $queues, $jobs, $from, $to, $limit)
            ->map(fn (array $values): array => [
                'id' => (string) $values['id'],
                'name' => (string) $values['name'],
                'queue' => (string) $values['queue'],
                'queued_at' => (float) $values['created_at'],
            ]);
    }

    /**
     * Выполненные джобы периода, новые первыми — как у Horizon, по времени завершения.
     *
     * @param list<string>|null $queues null — все
     * @param list<string>|null $jobs null — все
     * @param float|null $from Начало периода, unix-время; null — без границы
     * @param float|null $to Конец периода
     * @return Collection<int, array{id: string, name: string, queue: string, queued_at: float,
     *     reserved_at: float, completed_at: float}>
     */
    public function getCompleted(?array $queues, ?array $jobs, ?float $from, ?float $to, int $limit): Collection
    {
        return $this->scan('completed_jobs', self::COMPLETED_FIELDS, 'completed_at', $queues, $jobs, $from, $to, $limit)
            ->map(fn (array $values): array => [
                'id' => (string) $values['id'],
                'name' => (string) $values['name'],
                'queue' => (string) $values['queue'],
                'queued_at' => (float) $values['created_at'],
                'reserved_at' => (float) $values['reserved_at'],
                'completed_at' => (float) $values['completed_at'],
            ]);
    }

    /**
     * Упавшие джобы периода, новые первыми — как у Horizon, по времени падения. attempts — сколько раз джоб
     * брал воркер; exception — первая строка исключения, как его хранит Horizon:
     * «Illuminate\Database\QueryException: SQLSTATE… in /app/…/Connection.php:825».
     *
     * @param list<string>|null $queues null — все
     * @param list<string>|null $jobs null — все
     * @param float|null $from Начало периода, unix-время; null — без границы
     * @param float|null $to Конец периода
     * @return Collection<int, array{id: string, name: string, queue: string, attempts: int, reserved_at: float|null,
     *     failed_at: float, exception: string|null}>
     */
    public function getFailed(?array $queues, ?array $jobs, ?float $from, ?float $to, int $limit): Collection
    {
        $rows = $this->scan('failed_jobs', self::FAILED_FIELDS, 'failed_at', $queues, $jobs, $from, $to, $limit);
        $exceptions = $this->exceptionFirstLines(array_column($rows->all(), 'id'));

        return $rows->map(function (array $values, int $index) use ($exceptions): array {
            $payload = json_decode((string) $values['payload'], true);

            return [
                'id' => (string) $values['id'],
                'name' => (string) $values['name'],
                'queue' => (string) $values['queue'],
                'attempts' => (int) ($payload['attempts'] ?? 0),
                // Джоб мог упасть, не дойдя до воркера: тогда времени выполнения нет
                'reserved_at' => $values['reserved_at'] ? (float) $values['reserved_at'] : null,
                'failed_at' => (float) $values['failed_at'],
                'exception' => $exceptions[$index],
            ];
        });
    }

    /**
     * Поля последних джобов списка $type за период, с фильтрами по очереди и классу.
     *
     * @param list<string> $fields
     * @param list<string>|null $queues
     * @param list<string>|null $jobs
     * @return Collection<int, array<string, string|null>>
     */
    private function scan(
        string $type,
        array $fields,
        string $timeField,
        ?array $queues,
        ?array $jobs,
        ?float $from,
        ?float $to,
        int $limit,
    ): Collection {
        $redis = Redis::connection('horizon');
        $queues = $queues === null ? null : array_flip($queues);
        $jobs = $jobs === null ? null : array_flip($jobs);
        // Score — время со знаком минус: период [from, to] — это score от −to до −from
        $minScore = $to === null ? '-inf' : sprintf('%.6F', -$to);
        $maxScore = $from === null ? '+inf' : sprintf('%.6F', -$from);
        $result = [];

        for ($offset = 0; $offset < self::SCAN_LIMIT && count($result) < $limit; $offset += self::CHUNK) {
            $ids = $redis->zrangebyscore($type, $minScore, $maxScore, [
                'limit' => ['offset' => $offset, 'count' => self::CHUNK],
            ]);

            if ($ids === []) {
                break;
            }

            $rows = $redis->pipeline(function ($pipe) use ($ids, $fields): void {
                foreach ($ids as $id) {
                    $pipe->hmget($id, $fields);
                }
            });

            foreach ($rows as $row) {
                // phpredis отдаёт hmget словарём, predis — списком
                $values = is_array($row) ? array_combine($fields, array_values($row)) : null;

                // Hash уже истёк, а id ещё не вычищен из списка
                if ($values === null || !$values['id'] || !$values[$timeField]) {
                    continue;
                }

                if (
                    ($queues !== null && !isset($queues[$values['queue']]))
                    || ($jobs !== null && !isset($jobs[$values['name']]))
                ) {
                    continue;
                }

                $result[] = $values;

                if (count($result) >= $limit) {
                    break;
                }
            }
        }

        return collect($result);
    }

    /**
     * Первые строки исключений упавших джобов в порядке $ids; null — исключения нет. Обрезаются в Redis:
     * в ответ уходит одна строка, а не исключение со стеком.
     *
     * @param list<string> $ids
     * @return list<string|null>
     */
    private function exceptionFirstLines(array $ids): array
    {
        $redis = Redis::connection('horizon');
        $lines = [];

        foreach (array_chunk($ids, self::EXCEPTION_CHUNK) as $chunk) {
            $reply = $redis->eval(self::EXCEPTION_SCRIPT, count($chunk), ...[...$chunk, self::EXCEPTION_MAX_BYTES]);

            // phpredis на ошибку Lua возвращает false: тогда пусто только исключение, а не весь список
            foreach (array_keys($chunk) as $index) {
                $line = is_array($reply) ? ($reply[$index] ?? null) : null;
                $lines[] = is_string($line) ? $line : null;
            }
        }

        return $lines;
    }
}
