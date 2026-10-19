<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\DashboardTruth\Ingest\Protobuf;
use DF\HorizonObservability\Tests\DashboardTruth\Ingest\Snappy;

/*
| The remote-write encoding of the ingest (T17, design section 6.3): the all-literal snappy block and the protobuf
| WriteRequest decode back to what was written, StaleNaN keeps its exact bit pattern and labels are sorted by name.
| The real Prometheus accepting it is proven by every run of the pipeline.
*/

pest()->group('grafana');

it('round-trips snappy literal blocks at every tag boundary', function (int $length): void {
    $data = $length === 0 ? '' : substr(str_repeat(hash('sha256', (string) $length, true), intdiv($length, 32) + 1), 0, $length);
    $block = Snappy::compress($data);

    expect(Snappy::decompress($block))->toBe($data)
        ->and(strlen($block))->toBeLessThanOrEqual($length + 5 + 3 * (intdiv($length, 65536) + 1));
})->with([0, 1, 59, 60, 61, 255, 256, 257, 65535, 65536, 65537, 200000]);

it('decodes snappy copies, so it reads any valid block', function (): void {
    // "abcd" then a 1-byte-offset copy of 4 bytes at distance 4: "abcdabcd"
    $block = Protobuf::varint(8).chr(3 << 2).'abcd'.chr((0 << 2) | 1).chr(4);

    expect(Snappy::decompress($block))->toBe('abcdabcd');
});

it('encodes a write request with sorted labels, exact values and StaleNaN', function (): void {
    $bytes = Protobuf::writeRequest([
        ['labels' => ['job' => 'app', '__name__' => 'ho_queue_jobs_total', 'instance' => 'app:8080'], 'samples' => [[1740960003200, 3.0], [1740960013200, null], [1740960023200, INF]]],
        ['labels' => ['__name__' => 'up'], 'samples' => [[1740960003200, 1.0]]],
    ]);
    $decoded = Protobuf::decodeWriteRequest($bytes);

    expect($decoded)->toHaveCount(2)
        ->and(array_keys($decoded[0]['labels']))->toBe(['__name__', 'instance', 'job'])
        ->and($decoded[0]['samples'][0])->toBe([1740960003200, 3.0])
        ->and($decoded[0]['samples'][1])->toBe([1740960013200, null])
        ->and($decoded[0]['samples'][2])->toBe([1740960023200, INF])
        ->and($decoded[1])->toBe(['labels' => ['__name__' => 'up'], 'samples' => [[1740960003200, 1.0]]])
        ->and(bin2hex(pack('P', Protobuf::STALE_NAN)))->toBe('020000000000f07f');
});

it('writes varints as protobuf does', function (int $value, string $hex): void {
    $offset = 0;

    expect(bin2hex(Protobuf::varint($value)))->toBe($hex)
        ->and(Protobuf::readVarint(Protobuf::varint($value), $offset))->toBe($value);
})->with([[0, '00'], [1, '01'], [127, '7f'], [128, '8001'], [300, 'ac02'], [1740960003200, '80e9b5cad532']]);
