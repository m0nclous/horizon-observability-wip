<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource as BaseJsonResource;

/**
 * Ресурсы списков джобов: без обёртки у отдельного ресурса, кириллица и слэши в JSON как есть.
 * Коллекция по-прежнему в {"data": [...]}: по этому ключу их читает дашборд.
 */
class JsonResource extends BaseJsonResource
{
    public static $wrap = null;

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
