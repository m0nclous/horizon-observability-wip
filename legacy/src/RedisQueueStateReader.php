<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

/**
 * Читает состояние Redis-очередей Horizon: ожидающие, взятые воркерами и отложенные джобы,
 * в том числе отложенные с наступившим сроком, которые воркеры ещё не перенесли в очередь.
 *
 * На каждую очередь — один Lua-скрипт. Он просматривает не больше pending_scan_limit
 * джобов из головы очереди и весь :reserved (до RESERVED_SCAN_LIMIT), достаёт displayName
 * поиском по строке, без разбора JSON, и возвращает только счётчики. Пока скрипт работает,
 * Redis заблокирован, поэтому очереди читаются отдельными вызовами: между ними Redis
 * успевает обслужить воркеры.
 */
class RedisQueueStateReader
{
    private const UNSCANNED = '(не просмотрено)';
    private const UNKNOWN = '(неизвестно)';

    // :reserved не длиннее числа процессов, если воркеры не убиты пачками
    private const RESERVED_SCAN_LIMIT = 1000;

    // KEYS: очередь, её :reserved и :delayed. ARGV: лимиты просмотра ожидающих и зарезервированных,
    // текущее unix-время.
    // Ответ: длина очереди, ZCARD :reserved, ZCARD :delayed, момент постановки головного джоба
    // (pushedAt от Horizon, иначе createdAt; nil, если очередь пуста), пары «displayName, количество»
    // для ожидающих и для зарезервированных, число отложенных с наступившим сроком и самый ранний
    // такой срок (nil, если их нет).
    // delay в payload не учитывается: Horizon его не пишет, головной джоб, отложенный при отправке,
    // стареет от отправки, а не от наступления срока.
    // Поиск без шаблонов: после ретрая cjson переставляет ключи payload, displayName
    // оказывается в конце, и string.match на таком payload в разы медленнее.
    private const SCRIPT = <<<'LUA'
        local function displayName(payload)
            local _, from = string.find(payload, '"displayName":"', 1, true)
            if not from then
                return ''
            end
            local to = string.find(payload, '"', from + 1, true)
            return to and string.sub(payload, from + 1, to - 1) or ''
        end

        -- целая часть числа; pushedAt от Horizon лежит JSON-строкой
        local function integer(payload, key)
            local _, from = string.find(payload, '"' .. key .. '":', 1, true)
            return from and tonumber(string.match(payload, '^"?(%d+)', from + 1))
        end

        local length = redis.call('llen', KEYS[1])
        local limit = math.min(length, tonumber(ARGV[1]))
        local pending, head = {}, false
        for offset = 0, limit - 1, 500 do
            for _, payload in ipairs(redis.call('lrange', KEYS[1], offset, math.min(offset + 499, limit - 1))) do
                head = head or payload
                local name = displayName(payload)
                pending[name] = (pending[name] or 0) + 1
            end
        end
        if not head and length > 0 then
            head = redis.call('lindex', KEYS[1], 0)
        end

        local reserved = {}
        local items = redis.call('zrange', KEYS[2], 0, tonumber(ARGV[2]) - 1)
        for _, payload in ipairs(items) do
            local name = displayName(payload)
            reserved[name] = (reserved[name] or 0) + 1
        end
        local reservedTotal = #items
        if reservedTotal >= tonumber(ARGV[2]) then
            reservedTotal = redis.call('zcard', KEYS[2])
        end

        -- В очередь отложенные переносит только pop() воркера этой очереди: пока все её
        -- воркеры заняты, джобы с наступившим сроком лежат в :delayed. score = срок
        local delayed, due, firstDueAt = redis.call('zcard', KEYS[3]), 0, false
        if delayed > 0 then
            local first = redis.call('zrange', KEYS[3], 0, 0, 'WITHSCORES')
            if first[2] and tonumber(first[2]) <= tonumber(ARGV[3]) then
                firstDueAt = first[2]
                due = redis.call('zcount', KEYS[3], '-inf', ARGV[3])
            end
        end

        local readyAt = head and (integer(head, 'pushedAt') or integer(head, 'createdAt')) or false

        local function flatten(counts)
            local result = {}
            for name, count in pairs(counts) do
                result[#result + 1] = name
                result[#result + 1] = count
            end
            return result
        end

        return {length, reservedTotal, delayed, readyAt, flatten(pending), flatten(reserved), due, firstDueAt}
        LUA;

    public function __construct(
        private readonly HorizonQueues $horizonQueues,
        private readonly QueueFactory $queues,
    ) {
    }

    /**
     * Сбой одной очереди не прерывает чтение остальных: она пропускается,
     * а все сбои пишутся в лог одним предупреждением на сбор.
     *
     * @return list<QueueState>
     */
    public function read(): array
    {
        $states = [];
        $errors = [];

        foreach ($this->horizonQueues->byConnection() as $connection => $queues) {
            foreach ($queues as $queue) {
                try {
                    $state = $this->readQueue($connection, $queue);
                } catch (Throwable $exception) {
                    $errors[$exception->getMessage()][] = $connection . ':' . $queue;

                    continue;
                }

                if ($state !== null) {
                    $states[] = $state;
                }
            }
        }

        if ($errors !== []) {
            Log::warning('QueueMetrics: не удалось прочитать состояние очередей', ['errors' => $errors]);
        }

        return $states;
    }

    private function readQueue(string $connection, string $queue): ?QueueState
    {
        $redisQueue = $this->queues->connection($connection);

        if (!$redisQueue instanceof RedisQueue) {
            return null;
        }

        $redis = $redisQueue->getConnection();
        $key = $redisQueue->getQueue($queue);
        $reply = $redis->eval(
            self::SCRIPT,
            3,
            $key,
            $key . ':reserved',
            $key . ':delayed',
            max(0, (int) config('horizon-prometheus-grafana.pending_scan_limit')),
            self::RESERVED_SCAN_LIMIT,
            time(),
        );

        // phpredis не бросает исключение на ошибку Lua, а возвращает false
        if (!is_array($reply) || count($reply) !== 8 || !is_array($reply[4]) || !is_array($reply[5])) {
            throw new UnexpectedValueException('Lua-скрипт вернул ' . $this->describeFailure($redis, $reply));
        }

        [$pending, $reserved, $delayed, $headQueuedAt, $pendingPairs, $reservedPairs, $due, $firstDueAt] = $reply;

        // Дольше всех ждёт либо головной джоб, либо самый ранний отложенный с наступившим сроком
        $readySince = array_map(intval(...), array_filter([$headQueuedAt, $firstDueAt], is_numeric(...)));

        return new QueueState(
            connection: $connection,
            queue: $queue,
            pending: (int) $pending,
            delayed: (int) $delayed,
            delayedDue: (int) $due,
            oldestReadyAt: $readySince === [] ? null : min($readySince),
            pendingByClass: $this->byClass((int) $pending, $pendingPairs),
            reservedByClass: $this->byClass((int) $reserved, $reservedPairs),
        );
    }

    /**
     * Количество по классам. Остаток за пределами просмотра идёт в «(не просмотрено)», чтобы сумма
     * сходилась с общим числом.
     *
     * @param list<string|int> $pairs
     * @return array<string, int>
     */
    private function byClass(int $total, array $pairs): array
    {
        $result = [];

        foreach (array_chunk($pairs, 2) as [$displayName, $count]) {
            $class = $this->jobClass((string) $displayName);
            $result[$class] = ($result[$class] ?? 0) + (int) $count;
        }

        if ($total > array_sum($result)) {
            $result[self::UNSCANNED] = $total - array_sum($result);
        }

        return $result;
    }

    /**
     * displayName вырезан из JSON как есть, поэтому обратные слэши в нём экранированы.
     */
    private function jobClass(string $displayName): string
    {
        if ($displayName === '') {
            return self::UNKNOWN;
        }

        $decoded = json_decode('"' . $displayName . '"');

        return is_string($decoded) ? $decoded : $displayName;
    }

    private function describeFailure(Connection $redis, mixed $reply): string
    {
        $client = $redis->client();
        $error = method_exists($client, 'getLastError') ? $client->getLastError() : null;

        if (is_string($error) && method_exists($client, 'clearLastError')) {
            $client->clearLastError();
        }

        return is_string($error) ? 'ошибку: ' . $error : 'неожиданный ответ: ' . get_debug_type($reply);
    }
}
