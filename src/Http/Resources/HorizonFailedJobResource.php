<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Http\Resources;

use SushiMarket\HorizonPrometheusGrafana\Http\Resources\JsonResource;

/**
 * Время — unix-время в миллисекундах, как его ждёт Grafana; runtime — секунды, как у Horizon,
 * null — джоб упал, не дойдя до воркера; exception — класс и сообщение исключения («QueryException: SQLSTATE…»);
 * horizon_path — страница джоба в Horizon относительно его адреса
 */
class HorizonFailedJobResource extends JsonResource
{
    public function toArray($request): array
    {
        $reservedAt = $this->resource['reserved_at'];

        return [
            'id' => (string) $this->resource['id'],
            'name' => (string) $this->resource['name'],
            'queue' => (string) $this->resource['queue'],
            'attempts' => (int) $this->resource['attempts'],
            'failed_at' => (int) round($this->resource['failed_at'] * 1000),
            'runtime' => $reservedAt === null ? null : round($this->resource['failed_at'] - $reservedAt, 3),
            'exception' => $this->resource['exception'] === null ? null : (string) $this->resource['exception'],
            'horizon_path' => 'failed/' . $this->resource['id'],
        ];
    }
}
