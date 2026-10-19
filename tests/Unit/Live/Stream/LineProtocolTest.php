<?php

declare(strict_types=1);

use DF\HorizonObservability\Live\LiveScope;
use DF\HorizonObservability\Live\LiveSnapshot;
use DF\HorizonObservability\Live\Stream\LineProtocol;

/*
| The Influx line protocol of a push: tagless tiles rows 1 ms apart (pending, delayed, supervisors by display name,
| throughput), floats without the i suffix, -1 for null, escaped string fields, the delayed window and the
| "unavailable" frame.
*/

const LINE_PROTOCOL_T = 1792201716123000000;

function lineProtocolSnapshot(?int $status = 1, bool $jobFilter = false): LiveSnapshot
{
    $scope = new LiveScope(null, [['connection' => 'redis', 'queue' => 'default']], $jobFilter, $jobFilter ? ['App\\Jobs\\A'] : null);

    return LiveSnapshot::compose($scope, 1792201716123, 1000, $status, [
        ['name' => 'supervisor-default', 'connection' => 'redis', 'balance' => 'auto', 'processes' => 3, 'min_processes' => 1, 'max_processes' => 3, 'busy' => 2, 'queues' => ['default']],
        ['name' => 'supervisor-say "hi" \\ there', 'connection' => 'redis', 'balance' => 'off', 'processes' => 1, 'min_processes' => 1, 'max_processes' => 10, 'busy' => 9, 'queues' => ['default']],
    ], [
        ['connection' => 'redis', 'queue' => 'default', 'pending' => 7, 'reserved' => 2, 'delayed' => 4, 'delayed_due' => 0, 'oldest_seconds' => 17, 'wait_threshold_seconds' => 60, 'completed_per_minute' => 42, 'failed_per_minute' => 1.0, 'supervisors' => ['supervisor-default']],
    ], $jobFilter ? null : ['completed' => 42, 'failed' => 1, 'retried' => 0]);
}

it('encodes the tiles 1 ms apart and the delayed window (golden)', function (): void {
    $window = [[1792201706000, 3.0], [1792201711000, 4.0], [1792201716123, 4.0]];

    expect(LineProtocol::encode(lineProtocolSnapshot(), LINE_PROTOCOL_T, $window))->toBe(<<<'TXT'
        tiles tile="pending",key="",caption="Oldest: 17 s · default",value=7.0,color="green",level=0.0 1792201716123000000
        tiles tile="delayed",key="",caption="",value=4.0,color="text",level=0.0 1792201716124000000
        tiles tile="supervisor",key="supervisor-default",caption="default · max 3",value=3.0,color="green",level=0.0 1792201716125000000
        tiles tile="supervisor",key="supervisor-say \"hi\" \\ there",caption="say \"hi\" \\ there · max 10",value=1.0,color="red",level=2.0 1792201716126000000
        tiles tile="throughput",key="",caption="RPM: 42",value=42.0,color="text",level=0.0 1792201716127000000
        delayed value=3.0 1792201706000000000
        delayed value=4.0 1792201711000000000
        delayed value=4.0 1792201716123000000

        TXT);
});

it('pushes null as -1 and keeps the value field on every row', function (): void {
    $lines = explode("\n", trim(LineProtocol::encode(lineProtocolSnapshot(status: null, jobFilter: true), LINE_PROTOCOL_T, [[1792201711000, 4.0]])));

    expect($lines[0])->toBe('tiles tile="pending",key="",caption="Oldest: —",value=-1.0,color="green",level=0.0 1792201716123000000')
        ->and($lines[1])->toBe('tiles tile="delayed",key="",caption="",value=-1.0,color="text",level=0.0 1792201716124000000')
        ->and($lines[4])->toBe('tiles tile="throughput",key="",caption="RPM: —",value=-1.0,color="text",level=0.0 1792201716127000000')
        // a null current delayed value adds no row; the window stays
        ->and(array_slice($lines, 5))->toBe(['delayed value=4.0 1792201711000000000']);

    foreach (array_slice($lines, 0, 5) as $line) {
        expect($line)->toMatch('/,value=-?\d+\.\d+,/');
    }
});

it('keeps only the window points older than the push, at most 179, sorted', function (): void {
    $window = [];

    foreach (range(0, 199) as $i) {
        $window[] = [1792201716123 - (200 - $i) * 5000, (float) $i];
    }

    $window[] = [1792201716123, 999.0]; // the point the leader just stored, at the push time
    shuffle($window);

    $delayed = array_values(array_filter(explode("\n", trim(LineProtocol::encode(lineProtocolSnapshot(), LINE_PROTOCOL_T, $window))), static fn (string $line): bool => str_starts_with($line, 'delayed ')));

    expect($delayed)->toHaveCount(180)
        ->and($delayed[0])->toBe('delayed value=21.0 '.((1792201716123 - 179 * 5000) * 1000000))
        ->and($delayed[178])->toBe('delayed value=199.0 '.((1792201716123 - 5000) * 1000000))
        ->and($delayed[179])->toBe('delayed value=4.0 1792201716123000000');
});

it('formats numbers as floats without exponents for counts', function (int|float|null $value, string $expected): void {
    expect(LineProtocol::number($value))->toBe($expected);
})->with([
    [0, '0.0'],
    [7, '7.0'],
    [-1, '-1.0'],
    [0.667, '0.667'],
    [123456789012, '123456789012.0'],
    [null, '-1.0'],
    [NAN, '-1.0'],
    [INF, '-1.0'],
]);

it('escapes backslashes and quotes and never emits a line break inside a field', function (): void {
    expect(LineProtocol::string('a "b" \\c'))->toBe('"a \\"b\\" \\\\c"')
        ->and(LineProtocol::string("one\ntwo\r\nthree\rfour"))->toBe('"one two three four"');
});

it('pushes invalid UTF-8 as U+FFFD, like the cached snapshot JSON', function (): void {
    $snapshot = LiveSnapshot::compose(new LiveScope(null, [['connection' => 'redis', 'queue' => "caf\xE9"]], false, null), 1792201716123, 1000, 1, [
        ['name' => "supervisor-caf\xE9", 'connection' => 'redis', 'balance' => 'auto', 'processes' => 1, 'min_processes' => 1, 'max_processes' => 1, 'busy' => 0, 'queues' => ["caf\xE9"]],
    ], [
        ['connection' => 'redis', 'queue' => "caf\xE9", 'pending' => 1, 'reserved' => 0, 'delayed' => 0, 'delayed_due' => 0, 'oldest_seconds' => 3.0, 'wait_threshold_seconds' => 60, 'completed_per_minute' => null, 'failed_per_minute' => null, 'supervisors' => []],
    ], null);
    $cached = LiveSnapshot::fromArray(json_decode($snapshot->toJson(), true, 64, JSON_THROW_ON_ERROR));
    $lines = LineProtocol::encode($snapshot, LINE_PROTOCOL_T, []);

    expect(LineProtocol::string("caf\xE9\xE9"))->toBe("\"caf\u{FFFD}\u{FFFD}\"")
        ->and(LineProtocol::string("caf\u{00E9}"))->toBe("\"caf\u{00E9}\"")
        ->and(preg_match('//u', $lines))->toBe(1)
        ->and($lines)->toContain("key=\"supervisor-caf\u{FFFD}\"")
        ->and($lines)->toContain("caption=\"Oldest: 3 s \u{00B7} caf\u{FFFD}\"")
        ->and(LineProtocol::encode($cached, LINE_PROTOCOL_T, []))->toBe($lines);
});

it('encodes the unavailable frame for the rows of the last push, or the pending row only', function (): void {
    expect(LineProtocol::unavailable(LINE_PROTOCOL_T, []))->toBe(
        'tiles tile="pending",key="",caption="Live unavailable",value=-1.0,color="text",level=0.0 1792201716123000000'."\n",
    )->and(LineProtocol::unavailable(LINE_PROTOCOL_T, [
        ['tile' => 'pending', 'key' => ''],
        ['tile' => 'delayed', 'key' => ''],
        ['tile' => 'supervisor', 'key' => 'supervisor-default'],
        ['tile' => 'throughput', 'key' => ''],
    ]))->toBe(<<<'TXT'
        tiles tile="pending",key="",caption="Live unavailable",value=-1.0,color="text",level=0.0 1792201716123000000
        tiles tile="delayed",key="",caption="Live unavailable",value=-1.0,color="text",level=0.0 1792201716124000000
        tiles tile="supervisor",key="supervisor-default",caption="Live unavailable",value=-1.0,color="text",level=0.0 1792201716125000000
        tiles tile="throughput",key="",caption="Live unavailable",value=-1.0,color="text",level=0.0 1792201716126000000

        TXT);
});

it('has every field the stream panels read (TILE_ROWS: tile, key, caption, value, color; #9: value)', function (): void {
    $line = explode("\n", LineProtocol::encode(lineProtocolSnapshot(), LINE_PROTOCOL_T, []))[0];
    preg_match_all('/(?:^tiles |,)(\w+)=/', $line, $fields);

    expect($fields[1])->toBe(['tile', 'key', 'caption', 'value', 'color', 'level'])
        ->and(LineProtocol::TILES)->toBe('tiles')
        ->and(LineProtocol::DELAYED)->toBe('delayed');
});
