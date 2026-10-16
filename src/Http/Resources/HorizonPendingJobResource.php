<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Http\Resources;

use SushiMarket\HorizonPrometheusGrafana\Http\Resources\JsonResource;

/**
 * Время — unix-время в миллисекундах, как его ждёт Grafana; horizon_path — страница джоба в Horizon
 * относительно его адреса
 */
class HorizonPendingJobResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (string) $this->resource['id'],
            'name' => (string) $this->resource['name'],
            'queue' => (string) $this->resource['queue'],
            'queued_at' => (int) round($this->resource['queued_at'] * 1000),
            'horizon_path' => 'jobs/pending/' . $this->resource['id'],
        ];
    }
}
