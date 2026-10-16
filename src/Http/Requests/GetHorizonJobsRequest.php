<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use SushiMarket\HorizonPrometheusGrafana\DTO\GetHorizonJobsDTO;

/**
 * Фильтры приходят переменными дашборда в формате Grafana queryparam: var-queue=default&var-queue=slow,
 * а при «All» — var-queue=$__all, это значит без фильтра. Повторяющиеся параметры без [] PHP схлопывает
 * в последний, поэтому они разбираются из строки запроса.
 */
class GetHorizonJobsRequest extends FormRequest
{
    private const ALL = '$__all';

    // Переменная дашборда => поле DTO
    private const VARIABLES = [
        'supervisor' => 'supervisors',
        'batch_kind' => 'batches',
        'queue' => 'queues',
        'job_class' => 'jobs',
    ];

    public function rules(): array
    {
        return [
            'supervisors' => 'nullable|array',
            'supervisors.*' => 'string',
            'batches' => 'nullable|array',
            'batches.*' => 'string',
            'queues' => 'nullable|array',
            'queues.*' => 'string',
            'jobs' => 'nullable|array',
            'jobs.*' => 'string',
            'from' => 'nullable|integer|min:0',
            'to' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:1|max:1000',
        ];
    }

    protected function prepareForValidation(): void
    {
        $variables = $this->grafanaVariables();
        $filters = [];

        foreach (self::VARIABLES as $variable => $field) {
            $values = $variables[$variable] ?? [];
            $filters[$field] = $values === [] || in_array(self::ALL, $values, true) ? null : $values;
        }

        $this->merge($filters);
    }

    public function toData(): GetHorizonJobsDTO
    {
        return GetHorizonJobsDTO::from(array_filter($this->validated(), fn ($value): bool => $value !== null));
    }

    /**
     * @return array<string, list<string>>
     */
    private function grafanaVariables(): array
    {
        $variables = [];

        foreach (explode('&', (string) $this->server('QUERY_STRING')) as $pair) {
            [$name, $value] = array_map('urldecode', explode('=', $pair, 2) + [1 => '']);

            if (str_starts_with($name, 'var-')) {
                $variables[substr($name, 4)][] = $value;
            }
        }

        return $variables;
    }
}
