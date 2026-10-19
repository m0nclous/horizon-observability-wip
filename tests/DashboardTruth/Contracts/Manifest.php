<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Contracts;

use InvalidArgumentException;
use RuntimeException;

/**
 * What the record stage leaves for the later stages of one dataset (`datasets/<name>/manifest.json`, schema
 * `schema/manifest.schema.json`, design section 6.2): the kept world (Redis prefix, SQLite database, token), the
 * time frame [epoch, end] on the virtual clock, the scrape targets, the topology, the events, the model's attempt log
 * and the TTLs frozen at the end.
 *
 * Times are epoch seconds (floats where the recorder's clock had a fraction); sample times are milliseconds.
 */
final readonly class Manifest
{
    public const SCHEMA = 1;

    /**
     * @param  list<array{name: string, job: string, instance: string, interval: int, phase: float, from: int, to: int, failed: list<int>}>  $targets
     * @param  list<array{from: int, to: int, masters: list<array<string, mixed>>}>  $topology
     * @param  list<array{at: float, kind: string, detail: string}>  $events
     * @param  list<array{connection: string, queue: string, class: string, status: string, at: float}>  $attempts
     * @param  array<string, int>  $ttl  key (without the prefix) => remaining TTL in seconds when it was persisted
     * @param  list<string>  $renders  the renders whose cases use the dataset
     */
    public function __construct(
        public string $dataset,
        public int $epoch,
        public int $end,
        public int $scrapeInterval,
        public string $prefix,
        public string $sqlite,
        public string $token,
        public array $targets,
        public array $topology,
        public array $events,
        public array $attempts,
        public array $ttl,
        public array $renders,
    ) {
        if ($end <= $epoch) {
            throw new InvalidArgumentException('A dataset ends after its epoch.');
        }
    }

    public static function read(string $path): self
    {
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException(sprintf('%s is not a manifest of schema %d.', $path, self::SCHEMA));
        }

        Schema::assert('manifest', $data, $path);

        /** @var array{dataset: string, epoch: int, end: int, scrapeInterval: int, prefix: string, sqlite: string, token: string, targets: list<array{name: string, job: string, instance: string, interval: int, phase: float, from: int, to: int, failed: list<int>}>, topology: list<array{from: int, to: int, masters: list<array<string, mixed>>}>, events: list<array{at: float, kind: string, detail: string}>, attempts: list<array{connection: string, queue: string, class: string, status: string, at: float}>, ttl: array<string, int>, renders: list<string>} $data */
        return new self(
            $data['dataset'],
            $data['epoch'],
            $data['end'],
            $data['scrapeInterval'],
            $data['prefix'],
            $data['sqlite'],
            $data['token'],
            $data['targets'],
            $data['topology'],
            $data['events'],
            $data['attempts'],
            $data['ttl'],
            $data['renders'],
        );
    }

    public function write(string $path): void
    {
        $data = $this->toArray();
        Schema::assert('manifest', $data, $path);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'dataset' => $this->dataset,
            'epoch' => $this->epoch,
            'end' => $this->end,
            'scrapeInterval' => $this->scrapeInterval,
            'prefix' => $this->prefix,
            'sqlite' => $this->sqlite,
            'token' => $this->token,
            'targets' => $this->targets,
            'topology' => $this->topology,
            'events' => $this->events,
            'attempts' => $this->attempts,
            'ttl' => (object) $this->ttl,
            'renders' => $this->renders,
        ];
    }

    /**
     * The end V of the dataset in milliseconds: the fake browser clock of its cases.
     */
    public function endMs(): int
    {
        return $this->end * 1000;
    }
}
