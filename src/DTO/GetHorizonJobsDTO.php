<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\DTO;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Фильтры списков джобов Horizon как на дашборде: null — без фильтра (All)
 */
#[MapInputName(SnakeCaseMapper::class)]
class GetHorizonJobsDTO extends Data
{
    /**
     * @param list<string>|null $supervisors Супервизоры Horizon: их очереди
     * @param list<string>|null $batches Виды батчей (BatchKind): классы и очереди их джобов
     * @param list<string>|null $queues
     * @param list<string>|null $jobs Классы джобов (имя джоба в Horizon)
     * @param int|null $from Начало периода, unix-время в миллисекундах, как ${__from} в Grafana
     * @param int|null $to Конец периода
     */
    public function __construct(
        public ?array $supervisors = null,
        public ?array $batches = null,
        public ?array $queues = null,
        public ?array $jobs = null,
        public ?int $from = null,
        public ?int $to = null,
        public int $limit = 500,
    ) {
    }
}
