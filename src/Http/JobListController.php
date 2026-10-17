<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http;

use DF\HorizonObservability\Support\FailureReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * `GET|HEAD {base}/jobs/{list}`: `{"data":[...]}` for Grafana's Infinity datasource (Arch Q2.1).
 *
 * - The rows have the fields of {@see JobRows::fields()}; slashes and Unicode are not escaped, and an invalid UTF-8
 *   byte (a corrupt name in Redis) becomes U+FFFD instead of failing the response.
 * - Invalid input is a 422 JSON response ({@see JobListQuery::fromRequest()}).
 * - When Horizon's or a queue's Redis cannot be read, the answer is 503 `{"message":"Job list unavailable."}`
 *   and one throttled log line per list, instead of an exception (and its report) on every Grafana refresh.
 * - Responses are never cached (`Cache-Control: no-store`).
 *
 * @internal
 */
final readonly class JobListController
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    public const UNAVAILABLE = 'Job list unavailable.';

    public function __construct(private JobListService $service, private FailureReporter $reporter) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, string $list): JsonResponse
    {
        $jobList = JobList::tryFrom($list) ?? throw new NotFoundHttpException;
        $query = JobListQuery::fromRequest($request, $jobList);

        try {
            $rows = $this->service->rows($query);
        } catch (Throwable $e) {
            $this->reporter->report('jobs.'.$jobList->value, $e);

            return new JsonResponse(['message' => self::UNAVAILABLE], 503, ['Cache-Control' => 'no-store'], self::JSON_FLAGS);
        }

        return new JsonResponse(['data' => $rows], 200, ['Cache-Control' => 'no-store'], self::JSON_FLAGS);
    }
}
