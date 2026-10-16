<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Repositories;

use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

/**
 * Отложенные джобы, срок которых ещё не наступил, — как на плитке Delayed: sorted set «очередь:delayed» в Redis самой
 * очереди, score — время, когда джоб станет доступен воркеру. Horizon их отдельно не показывает: в его ожидающих
 * отложенный есть только horizon.trim.pending минут с постановки. Джобы с наступившим сроком уже готовы
 * к выполнению и считаются ожидающими.
 */
class DelayedJobsRepository
{
    private const CHUNK = 500;

    // Сколько ближайших джобов очереди просматривать с фильтром по классу
    private const SCAN_LIMIT = 10000;

    /**
     * Отложенные джобы, ближайшие первыми. attempts — сколько попыток уже было: больше нуля у повтора,
     * который ждёт паузы перед следующей попыткой.
     *
     * @param array<string, list<string>> $queuesByConnection Очереди по соединениям
     * @param list<string>|null $jobs Классы джобов (имя джоба в Horizon); null — все
     * @return Collection<int, array{id: string, name: string, queue: string, attempts: int, queued_at: float|null,
     *     available_at: float}>
     */
    public function getDelayed(array $queuesByConnection, ?array $jobs, int $limit): Collection
    {
        $jobs = $jobs === null ? null : array_flip($jobs);
        $result = [];

        foreach ($queuesByConnection as $connection => $queues) {
            $redisQueue = Queue::connection($connection);

            if (!$redisQueue instanceof RedisQueue) {
                continue;
            }

            foreach ($queues as $queue) {
                array_push($result, ...$this->scanQueue($redisQueue, $queue, $jobs, $limit));
            }
        }

        return collect($result)->sortBy('available_at')->take($limit)->values();
    }

    /**
     * Ближайшие $limit подходящих джобов одной очереди: в общий список попадут только они.
     *
     * @param array<string, int>|null $jobs
     * @return list<array{id: string, name: string, queue: string, attempts: int, queued_at: float|null,
     *     available_at: float}>
     */
    private function scanQueue(RedisQueue $redisQueue, string $queue, ?array $jobs, int $limit): array
    {
        $redis = $redisQueue->getConnection();
        $key = $redisQueue->getQueue($queue) . ':delayed';
        $result = [];

        for ($offset = 0; $offset < self::SCAN_LIMIT && count($result) < $limit; $offset += self::CHUNK) {
            // Строго позже текущего момента: у остальных срок наступил
            $entries = $redis->zrangebyscore($key, '(' . time(), '+inf', [
                'withscores' => true,
                'limit' => ['offset' => $offset, 'count' => self::CHUNK],
            ]);

            if (!is_array($entries) || $entries === []) {
                break;
            }

            foreach ($entries as $payload => $availableAt) {
                $data = json_decode((string) $payload, true);
                $name = (string) ($data['displayName'] ?? '');

                if (!is_array($data) || ($jobs !== null && !isset($jobs[$name]))) {
                    continue;
                }

                $result[] = [
                    'id' => (string) ($data['uuid'] ?? ''),
                    'name' => $name,
                    'queue' => $queue,
                    'attempts' => (int) ($data['attempts'] ?? 0),
                    // Время постановки пишет Horizon (pushedAt), у джобов, поставленных в обход него, его нет
                    'queued_at' => isset($data['pushedAt']) ? (float) $data['pushedAt'] : null,
                    'available_at' => (float) $availableAt,
                ];

                if (count($result) >= $limit) {
                    break;
                }
            }
        }

        return $result;
    }
}
