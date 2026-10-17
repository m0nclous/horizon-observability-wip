<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Parsed request of a job list (Arch Q2.1); the `var-*` filter parsing is shared with `GET {base}/live`
 * (Addendum C').
 *
 * - `limit`: integer 1-1000, default 500; `from` and `to`: optional Unix milliseconds, `from <= to`; delayed ignores
 *   them.
 * - Filters in Grafana's `${var:queryparam}` form: `var-queue=default&var-queue=slow`. PHP keeps only the last of
 *   repeated keys, so they are read from the raw query string (URL-decoded, `+` is a space). A missing variable or
 *   the value `$__all` means no filter (null); unknown `var-*` parameters are ignored; values keep their order
 *   without duplicates.
 * - Invalid input (a bad limit or period, or a filter value that is not UTF-8) throws a `ValidationException`, which
 *   Laravel renders as 422 JSON.
 *
 * @internal
 */
final readonly class JobListQuery
{
    /**
     * The dashboard variables a list or the live snapshot can be filtered by (contract-tested against the dashboard).
     */
    public const VARIABLES = ['supervisor', 'batch_kind', 'queue', 'job_class'];

    public const DEFAULT_LIMIT = 500;

    public const MAX_LIMIT = 1000;

    /**
     * The `var-*` value Grafana sends for "All".
     */
    public const ALL = '$__all';

    /**
     * @param  list<string>|null  $supervisors  null = no filter
     * @param  list<string>|null  $batchKinds  null = no filter
     * @param  list<string>|null  $queues  null = no filter
     * @param  list<string>|null  $jobClasses  null = no filter
     */
    public function __construct(
        public JobList $list,
        public int $limit,
        public ?int $fromMs,
        public ?int $toMs,
        public ?array $supervisors,
        public ?array $batchKinds,
        public ?array $queues,
        public ?array $jobClasses,
    ) {}

    /**
     * @throws ValidationException
     */
    public static function fromRequest(Request $request, JobList $list): self
    {
        $filters = self::filtersFromRequest($request);
        $query = $request->query->all();

        Validator::make($query, [
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'from' => ['nullable', 'integer', 'min:0'],
            'to' => ['nullable', 'integer', 'min:0'],
        ])->validate();

        $limit = self::integer($query['limit'] ?? null) ?? self::DEFAULT_LIMIT;
        $from = self::integer($query['from'] ?? null);
        $to = self::integer($query['to'] ?? null);

        if ($from !== null && $to !== null && $from > $to) {
            throw ValidationException::withMessages(['from' => 'The from time must not be after the to time.']);
        }

        if ($list === JobList::Delayed) {
            $from = $to = null;
        }

        return new self($list, $limit, $from, $to, $filters['supervisors'], $filters['batchKinds'], $filters['queues'], $filters['jobClasses']);
    }

    /**
     * The `var-*` filters of a request, shared by the job lists and `/live`.
     *
     * @return array{supervisors: list<string>|null, batchKinds: list<string>|null, queues: list<string>|null, jobClasses: list<string>|null}
     *
     * @throws ValidationException
     */
    public static function filtersFromRequest(Request $request): array
    {
        /** @var array<string, list<string>> $values */
        $values = [];
        /** @var array<string, true> $all */
        $all = [];
        $queryString = $request->server->get('QUERY_STRING');

        foreach (explode('&', is_string($queryString) ? $queryString : '') as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_map(urldecode(...), explode('=', $pair, 2) + [1 => '']);

            if (! str_starts_with($key, 'var-') || ! in_array($variable = substr($key, 4), self::VARIABLES, true)) {
                continue;
            }

            if (! mb_check_encoding($value, 'UTF-8')) {
                throw ValidationException::withMessages([$key => sprintf('The %s value must be valid UTF-8.', $key)]);
            }

            if ($value === self::ALL) {
                $all[$variable] = true;

                continue;
            }

            if (! in_array($value, $values[$variable] ?? [], true)) {
                $values[$variable][] = $value;
            }
        }

        $filter = static fn (string $variable): ?array => isset($all[$variable]) ? null : ($values[$variable] ?? null);

        return [
            'supervisors' => $filter('supervisor'),
            'batchKinds' => $filter('batch_kind'),
            'queues' => $filter('queue'),
            'jobClasses' => $filter('job_class'),
        ];
    }

    private static function integer(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) ? (int) $value : null;
    }
}
