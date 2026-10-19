<?php

declare(strict_types=1);

/*
| Caption, level and colour cases of the Overview tiles and the queue tables' live cells, shared by
| tests/Unit/Live/CaptionsTest and the Grafana renderer tests, so the poll/stream captions and the PromQL captions of
| the off mode agree. Each case is [arguments, expected]. Glyphs are escapes: U+00B7 MIDDLE DOT, U+2014 EM DASH.
*/

$dot = "\u{00B7}";
$dash = "\u{2014}";

return [
    'oldest' => [
        'unknown age' => [[null, 'default'], "Oldest: {$dash}"],
        'nothing waits' => [[0, 'default'], "Oldest: {$dash}"],
        'negative age' => [[-3, 'default'], "Oldest: {$dash}"],
        'no queue' => [[17, null], "Oldest: {$dash}"],
        'empty queue name' => [[17, ''], "Oldest: {$dash}"],
        'one second' => [[1, 'default'], "Oldest: 1 s {$dot} default"],
        'seconds' => [[17, 'default'], "Oldest: 17 s {$dot} default"],
        'last second value' => [[119, 'slow'], "Oldest: 119 s {$dot} slow"],
        'first minute value' => [[120, 'slow'], "Oldest: 2 min {$dot} slow"],
        'rounds down' => [[149, 'slow'], "Oldest: 2 min {$dot} slow"],
        'rounds half up' => [[150, 'slow'], "Oldest: 3 min {$dot} slow"],
        'minutes' => [[1020, 'slow'], "Oldest: 17 min {$dot} slow"],
        'pooled queue label' => [[42, 'mail,sms'], "Oldest: 42 s {$dot} mail,sms"],
    ],
    // The exact age (as exported, at most 3 decimals): the unit from the exact age, the number rounded once, as the
    // off-mode tile does with round(age < 120) and round((age >= 120) / 60).
    'oldest_exact' => [
        'unknown age' => [[null, 'default'], "Oldest: {$dash}"],
        'nothing waits' => [[0.0, 'default'], "Oldest: {$dash}"],
        'negative age' => [[-0.2, 'default'], "Oldest: {$dash}"],
        'not finite' => [[INF, 'default'], "Oldest: {$dash}"],
        'not a number' => [[NAN, 'default'], "Oldest: {$dash}"],
        'no queue' => [[17.2, null], "Oldest: {$dash}"],
        'empty queue name' => [[17.2, ''], "Oldest: {$dash}"],
        'waits less than half a second' => [[0.3, 'default'], "Oldest: 0 s {$dot} default"],
        'rounds half up' => [[0.5, 'default'], "Oldest: 1 s {$dot} default"],
        'seconds' => [[89.6, 'default'], "Oldest: 90 s {$dot} default"],
        'last second value' => [[119.4, 'slow'], "Oldest: 119 s {$dot} slow"],
        'rounds to 120 below the limit' => [[119.6, 'slow'], "Oldest: 120 s {$dot} slow"],
        'just below the limit' => [[119.999, 'slow'], "Oldest: 120 s {$dot} slow"],
        'first minute value' => [[120.0, 'slow'], "Oldest: 2 min {$dot} slow"],
        'minutes rounded once, down' => [[149.6, 'slow'], "Oldest: 2 min {$dot} slow"],
        'minutes rounded once, half up' => [[150.0, 'slow'], "Oldest: 3 min {$dot} slow"],
        'minutes rounded once, 3.495 min' => [[209.7, 'slow'], "Oldest: 3 min {$dot} slow"],
        'minutes' => [[1020.4, 'slow'], "Oldest: 17 min {$dot} slow"],
    ],
    'supervisor' => [
        'display name' => [['default', 3], "default {$dot} max 3"],
        'zero max' => [['pool', 0], "pool {$dot} max 0"],
        'unprefixed name' => [['workers', 12], "workers {$dot} max 12"],
    ],
    'display' => [
        'prefix stripped' => [['supervisor-default'], 'default'],
        'prefix only' => [['supervisor-'], 'supervisor-'],
        'no prefix' => [['pool'], 'pool'],
        'prefix stripped once' => [['supervisor-supervisor-x'], 'supervisor-x'],
        'prefix not at the start' => [['my-supervisor-1'], 'my-supervisor-1'],
    ],
    'rpm' => [
        'value' => [[42], 'RPM: 42'],
        'zero' => [[0], 'RPM: 0'],
        'no data' => [[null], "RPM: {$dash}"],
    ],
    'horizon' => [
        'running' => [[1], 'Running'],
        'paused' => [[0], 'Paused'],
        'no master' => [[-1], 'Stopped'],
    ],
    'horizon_level' => [
        'running' => [[1], 0],
        'paused' => [[0], 2],
        'no master' => [[-1], 2],
    ],
    'wait_level' => [
        'no ratio' => [[null], 0],
        'zero' => [[0.0], 0],
        'below the limit' => [[0.28], 0],
        'at the limit' => [[1.0], 0],
        'above the limit' => [[1.01], 1],
        'at twice the limit' => [[2.0], 1],
        'above twice the limit' => [[2.01], 2],
        'far above' => [[10.0], 2],
    ],
    'utilization_level' => [
        'idle' => [[0.0, true], 0],
        '69 percent' => [[0.69, true], 0],
        '70 percent after rounding' => [[0.696, true], 1],
        '70 percent' => [[0.7, true], 1],
        '89 percent' => [[0.894, true], 1],
        '90 percent after rounding' => [[0.896, true], 2],
        'full' => [[1.0, true], 2],
        'Horizon not running' => [[0.0, false], 2],
    ],
    'color' => [
        'level 0' => [[0], 'green'],
        'level 1' => [[1], 'yellow'],
        'level 2' => [[2], 'red'],
        'above 2' => [[3], 'red'],
        'below 0' => [[-1], 'green'],
    ],
    'percent_cell' => [
        'unknown' => [[null], 1999],
        'not finite' => [[NAN], 1999],
        'idle' => [[0.0], 1000],
        'rounds' => [[0.496], 1050],
        'full' => [[1.0], 1100],
    ],
    'duration' => [
        'zero' => [[0.0], '0 s'],
        'rounds half up' => [[9.5], '10 s'],
        'last second value' => [[119.4], '119 s'],
        'unit from the exact value' => [[119.6], '120 s'],
        'first minute value' => [[120.0], '2 min'],
        'minutes' => [[1020.0], '17 min'],
    ],
    'oldest_cell' => [
        'no limit' => [[12.3, null, 0], '0|12 s'],
        'within the limit' => [[5.0, 30, 0], '0|5 s / 30 s'],
        'over the limit' => [[45.0, 30, 1], '1|45 s / 30 s'],
        'minutes, the limit below 120 s in seconds' => [[300.0, 60, 2], '2|5 min / 60 s'],
    ],
];
