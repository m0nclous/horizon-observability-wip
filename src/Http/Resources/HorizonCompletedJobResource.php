<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Http\Resources;

use SushiMarket\HorizonPrometheusGrafana\Http\Resources\JsonResource;

/**
 * Время — unix-время в миллисекундах, как его ждёт Grafana; runtime — секунды, как у Horizon;
 * horizon_path — страница джоба в Horizon относительно его адреса
 */
class HorizonCompletedJobResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (string) $this->resource['id'],
            'name' => (string) $this->resource['name'],
            'queue' => (string) $this->resource['queue'],
            'queued_at' => (int) round($this->resource['queued_at'] * 1000),
            'completed_at' => (int) round($this->resource['completed_at'] * 1000),
            'runtime' => round($this->resource['completed_at'] - $this->resource['reserved_at'], 3),
            'horizon_path' => 'jobs/completed/' . $this->resource['id'],
        ];
    }
}
