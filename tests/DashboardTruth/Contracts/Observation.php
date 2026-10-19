<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Contracts;

use RuntimeException;

/**
 * One captured page load (`observations/<grafana>/<case>.json`, schema `schema/observation.schema.json`), as
 * browser/observe.mjs writes it: the `/api/ds/query` requests with their bodies and responses, the scene variables
 * and panels, the DOM of every panel, the router log lines of the load, page errors and timings.
 */
final readonly class Observation
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(public array $data, public string $path) {}

    public static function read(string $path): self
    {
        if (! is_file($path)) {
            throw new RuntimeException('No observation at '.$path.'.');
        }

        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException($path.' is not an observation.');
        }

        Schema::assert('observation', $data, $path);

        /** @var array<string, mixed> $data */
        return new self($data, $path);
    }

    public function complete(): bool
    {
        return $this->data['complete'] === true;
    }

    /**
     * @return list<string>
     */
    public function incomplete(): array
    {
        return $this->data['incomplete'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function requests(): array
    {
        return $this->data['requests'];
    }

    /**
     * The last answered request of a panel (its X-Panel-Id) that queried `$refId`, with its body and response.
     *
     * @return array<string, mixed>|null
     */
    public function request(string $panelId, string $refId): ?array
    {
        // The last answered one: the frontend aborts and re-sends a request when a variable settles
        foreach (array_reverse($this->requests()) as $request) {
            if (! is_array($request['response'] ?? null)) {
                continue;
            }

            if ($request['panelId'] !== $panelId || ! is_array($request['body'])) {
                continue;
            }

            foreach (is_array($request['body']['queries'] ?? null) ? $request['body']['queries'] : [] as $query) {
                if (is_array($query) && ($query['refId'] ?? null) === $refId) {
                    return $request;
                }
            }
        }

        return null;
    }

    /**
     * The scene variables by name.
     *
     * @return array<string, array{value: mixed, text: mixed, options: list<array{text: mixed, value: mixed}>, type: string}>
     */
    public function variables(): array
    {
        $variables = [];

        foreach ($this->data['variables'] as $variable) {
            $variables[(string) $variable['name']] = $variable;
        }

        return $variables;
    }

    /**
     * The scene panels (clones included) by key.
     *
     * @return array<string, array<string, mixed>>
     */
    public function panels(): array
    {
        $panels = [];

        foreach ($this->data['panels'] as $panel) {
            $panels[(string) $panel['key']] = $panel;
        }

        return $panels;
    }

    /**
     * The DOM read of a panel by its key (`panel-8`, `panel-7-clone-1`).
     *
     * @return array{key: string, panelId: string|null, title: string|null, lines: list<string>, sparkline: bool, error: bool, noData: bool}|null
     */
    public function dom(string $key): ?array
    {
        return $this->data['dom'][$key] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function router(): array
    {
        return $this->data['router'];
    }

    public function grafana(): string
    {
        return (string) $this->data['grafana'];
    }

    public function caseId(): string
    {
        return (string) $this->data['case'];
    }
}
