<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Http\Resources;

use SushiMarket\HorizonPrometheusGrafana\Http\Resources\JsonResource;

/**
 * Время — unix-время в миллисекундах, как его ждёт Grafana; queued_at — null, если джоб поставлен в обход Horizon
 */
class HorizonDelayedJobResource extends JsonResource
{
    public function toArray($request): array
    {
        $queuedAt = $this->resource['queued_at'];

        return [
            'id' => (string) $this->resource['id'],
            'name' => (string) $this->resource['name'],
            'queue' => (string) $this->resource['queue'],
            'attempts' => (int) $this->resource['attempts'],
            'queued_at' => $queuedAt === null ? null : (int) round($queuedAt * 1000),
            'available_at' => (int) round($this->resource['available_at'] * 1000),
        ];
    }
}
