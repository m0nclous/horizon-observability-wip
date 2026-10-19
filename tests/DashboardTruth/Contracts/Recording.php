<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Contracts;

use Generator;
use RuntimeException;

/**
 * The recorded scrapes of one dataset (`datasets/<name>/recording.ndjson`, one JSON object per line, schema
 * `schema/recording.schema.json`, design section 6.2): per scrape slot of a target its time in epoch milliseconds,
 * whether it succeeded and the exposition the real endpoint served. A failed slot has no exposition; `up` and the
 * staleness markers come from the ingest (Ingest/ScrapeEmulation).
 */
final class Recording
{
    /**
     * @var resource|null
     */
    private $handle;

    private function __construct(private readonly string $path) {}

    public static function create(string $path): self
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        $recording = new self($path);
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Cannot write '.$path.'.');
        }

        $recording->handle = $handle;

        return $recording;
    }

    public static function open(string $path): self
    {
        if (! is_file($path)) {
            throw new RuntimeException('No recording at '.$path.'.');
        }

        return new self($path);
    }

    public function append(string $target, int $timeMs, bool $ok, ?string $exposition): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('The recording is not open for writing.');
        }

        $line = ['target' => $target, 't' => $timeMs, 'ok' => $ok, 'exposition' => $ok ? $exposition : null];
        Schema::assert('recording', $line, $this->path);
        fwrite($this->handle, json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * Every scrape in recording order.
     *
     * @return Generator<int, array{target: string, t: int, ok: bool, exposition: string|null}>
     */
    public function scrapes(): Generator
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Cannot read '.$this->path.'.');
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }

                /** @var array{target: string, t: int, ok: bool, exposition: string|null} $scrape */
                $scrape = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

                yield $scrape;
            }
        } finally {
            fclose($handle);
        }
    }

    public function path(): string
    {
        return $this->path;
    }
}
