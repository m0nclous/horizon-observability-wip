<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Ingest;

use DF\HorizonObservability\Tests\DashboardTruth\Expect\Core\SeriesStore;
use RuntimeException;

/**
 * Writes stores into Prometheus' remote-write receiver (remote write 1.0: a snappy-compressed protobuf
 * `WriteRequest`, design section 6.3).
 *
 * Time-major: the distinct sample times of all stores of one dataset are cut into batches of {@see BATCH}
 * timestamps, and each request carries every series' samples of its batch, so Prometheus' head only ever moves
 * forward. Datasets must be written in epoch order into an empty Prometheus without an out-of-order window (verified:
 * design X3).
 */
final readonly class RemoteWrite
{
    public const BATCH = 60;

    public function __construct(private string $url) {}

    /**
     * Write one dataset (its stores merged time-major); the number of samples written.
     */
    public function write(SeriesStore ...$stores): int
    {
        $times = [];

        foreach ($stores as $store) {
            foreach ($store->times() as $time) {
                $times[$time] = true;
            }
        }

        $times = array_keys($times);
        sort($times);
        $written = 0;
        $series = [];

        foreach ($stores as $store) {
            foreach ($store->all() as $one) {
                $series[] = ['labels' => $one['labels'], 't' => $one['t'], 'v' => $one['v'], 'next' => 0];
            }
        }

        foreach (array_chunk($times, self::BATCH) as $batch) {
            $until = $batch[count($batch) - 1];
            $request = [];

            foreach ($series as &$one) {
                $samples = [];

                while ($one['next'] < count($one['t']) && $one['t'][$one['next']] <= $until) {
                    $samples[] = [$one['t'][$one['next']], $one['v'][$one['next']]];
                    $one['next']++;
                }

                if ($samples !== []) {
                    $request[] = ['labels' => $one['labels'], 'samples' => $samples];
                    $written += count($samples);
                }
            }

            unset($one);
            $this->post(Snappy::compress(Protobuf::writeRequest($request)));
        }

        return $written;
    }

    private function post(string $body): void
    {
        $curl = curl_init($this->url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-protobuf',
                'Content-Encoding: snappy',
                'X-Prometheus-Remote-Write-Version: 0.1.0',
                'User-Agent: horizon-observability-dashboard-truth',
            ],
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);

        if ($response === false || $status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Remote write to %s answered %d: %s', $this->url, $status, $response === false ? $error : trim((string) $response)));
        }
    }
}
