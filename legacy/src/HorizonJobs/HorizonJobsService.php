<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\HorizonJobs;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\SupervisorRepository;
use SushiMarket\HorizonPrometheusGrafana\DTO\GetHorizonJobsDTO;
use SushiMarket\HorizonPrometheusGrafana\HorizonQueues;
use SushiMarket\HorizonPrometheusGrafana\QueueJobMetricsStorage;
use SushiMarket\HorizonPrometheusGrafana\Repositories\DelayedJobsRepository;
use SushiMarket\HorizonPrometheusGrafana\Repositories\HorizonJobsRepository;

/**
 * Списки ожидающих, выполненных, упавших и отложенных джобов с фильтрами дашборда. Супервизор сужает очереди до своих,
 * вид батча — очереди и классы до тех, из которых состоят его батчи (как фильтр Batch на дашборде).
 */
class HorizonJobsService
{
    public function __construct(
        private readonly HorizonJobsRepository $repository,
        private readonly DelayedJobsRepository $delayedRepository,
        private readonly HorizonQueues $horizonQueues,
        private readonly QueueJobMetricsStorage $storage,
        private readonly SupervisorRepository $supervisors,
    ) {
    }

    /**
     * @return Collection<int, array<string, string|float>>
     */
    public function getPending(GetHorizonJobsDTO $DTO): Collection
    {
        return $this->repository->getPending(...$this->filters($DTO));
    }

    /**
     * @return Collection<int, array<string, string|float>>
     */
    public function getCompleted(GetHorizonJobsDTO $DTO): Collection
    {
        return $this->repository->getCompleted(...$this->filters($DTO));
    }

    /**
     * @return Collection<int, array<string, string|int|float|null>>
     */
    public function getFailed(GetHorizonJobsDTO $DTO): Collection
    {
        return $this->repository->getFailed(...$this->filters($DTO))
            ->map(fn (array $job): array => [...$job, 'exception' => $this->exceptionSummary($job['exception'])]);
    }

    /**
     * Отложенные сейчас, ближайшие первыми: это состояние очередей, а не история, поэтому период не применяется.
     * Очереди — те, что поднимает Horizon (HorizonQueues), с фильтрами дашборда.
     *
     * @return Collection<int, array<string, string|int|float|null>>
     */
    public function getDelayed(GetHorizonJobsDTO $DTO): Collection
    {
        ['queues' => $queues, 'jobs' => $jobs, 'limit' => $limit] = $this->filters($DTO);
        $queuesByConnection = array_map(
            fn (array $connectionQueues): array => $queues === null
                ? $connectionQueues
                : array_values(array_intersect($connectionQueues, $queues)),
            $this->horizonQueues->byConnection(),
        );

        return $this->delayedRepository->getDelayed($queuesByConnection, $jobs, $limit);
    }

    /**
     * Аргументы репозитория: очереди, классы, начало и конец периода в секундах, лимит.
     *
     * @return array{queues: list<string>|null, jobs: list<string>|null, from: float|null, to: float|null, limit: int}
     */
    private function filters(GetHorizonJobsDTO $DTO): array
    {
        $queues = $DTO->queues;
        $jobs = $DTO->jobs;

        if ($DTO->supervisors !== null) {
            $queues = $this->intersect($queues, $this->supervisorQueues($DTO->supervisors));
        }

        if ($DTO->batches !== null) {
            $links = $this->batchJobClasses($DTO->batches);
            $queues = $this->intersect($queues, array_column($links, 0));
            $jobs = $this->intersect($jobs, array_column($links, 1));
        }

        return [
            'queues' => $queues,
            'jobs' => $jobs,
            'from' => $DTO->from === null ? null : $DTO->from / 1000,
            'to' => $DTO->to === null ? null : $DTO->to / 1000,
            'limit' => $DTO->limit,
        ];
    }

    /**
     * Исключение коротко, как имя джоба: класс без пространства имён и сообщение, без места выброса.
     * «Illuminate\Database\QueryException: SQLSTATE… in /app/…/Connection.php:825» → «QueryException: SQLSTATE…»
     */
    private function exceptionSummary(?string $firstLine): ?string
    {
        if ($firstLine === null || $firstLine === '') {
            return null;
        }

        return preg_replace(
            [
                // За «@anonymous» в имени анонимного класса идут NUL, файл, строка и номер
                '/@anonymous\x00\S*?\$[0-9a-f]+/',
                '/ in \S+:\d+$/',
                '/^(?:\w+\\\\)+/',
            ],
            ['@anonymous', '', ''],
            // Redis обрезает строку по байтам: оборванный символ UTF-8 сломал бы JSON
            mb_scrub($firstLine, 'UTF-8'),
        );
    }

    /**
     * @param list<string>|null $filter
     * @param list<string> $values
     * @return list<string>
     */
    private function intersect(?array $filter, array $values): array
    {
        return array_values(array_unique($filter === null ? $values : array_intersect($filter, $values)));
    }

    /**
     * Очереди работающих супервизоров; имя — без префикса мастера, как метка supervisor в метриках.
     *
     * @param list<string> $names
     * @return list<string>
     */
    private function supervisorQueues(array $names): array
    {
        $queues = [];

        foreach ($this->supervisors->all() as $supervisor) {
            if (!in_array(Str::afterLast($supervisor->name, ':'), $names, true)) {
                continue;
            }

            // Ключ — «соединение:очередь»
            foreach (array_keys($supervisor->processes ?? []) as $queue) {
                $queues[] = Str::after((string) $queue, ':');
            }
        }

        return $queues;
    }

    /**
     * @param list<string> $kinds
     * @return list<array{0: string, 1: string}> очередь и класс
     */
    private function batchJobClasses(array $kinds): array
    {
        $links = [];

        foreach ($this->storage->batchJobClasses() as [$kind, , $queue, $job]) {
            if (in_array($kind, $kinds, true)) {
                $links[] = [$queue, $job];
            }
        }

        return $links;
    }
}
