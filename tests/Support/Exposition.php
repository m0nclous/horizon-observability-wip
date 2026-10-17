<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Support;

use InvalidArgumentException;

/**
 * Parser of a Prometheus text exposition (format 0.0.4) for assertions:
 * sample name => [labels key => value], plus the HELP and TYPE lines per family.
 *
 * The labels key is the label set sorted by name, `a="1",b="2"` (empty for no labels), so
 * `$exposition->value('ho_queue_jobs_total', ['queue' => 'default', ...])` finds a sample whatever the label order.
 * Values are floats; `+Inf`, `-Inf` and `NaN` are parsed. Malformed lines throw.
 */
final readonly class Exposition
{
    /**
     * @param  array<string, array<string, float>>  $samples
     * @param  array<string, string>  $help  family => unescaped HELP text
     * @param  array<string, string>  $types  family => TYPE
     */
    private function __construct(public array $samples, public array $help, public array $types) {}

    public static function parse(string $text): self
    {
        $samples = [];
        $help = [];
        $types = [];

        foreach (explode("\n", $text) as $number => $line) {
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '# HELP ')) {
                [$family, $value] = explode(' ', substr($line, 7), 2) + [1 => ''];
                $help[$family] = strtr($value, ['\\\\' => '\\', '\\n' => "\n"]);

                continue;
            }

            if (str_starts_with($line, '# TYPE ')) {
                [$family, $type] = explode(' ', substr($line, 7), 2) + [1 => ''];
                $types[$family] = $type;

                continue;
            }

            if (str_starts_with($line, '#')) {
                continue;
            }

            [$name, $labels, $value] = self::sample($line, $number + 1);
            $key = self::key($labels);

            if (isset($samples[$name][$key])) {
                throw new InvalidArgumentException(sprintf('Line %d: duplicate sample %s{%s}.', $number + 1, $name, $key));
            }

            $samples[$name][$key] = $value;
        }

        return new self($samples, $help, $types);
    }

    /**
     * The value of one sample, or null when it is absent.
     *
     * @param  array<string, string>  $labels
     */
    public function value(string $name, array $labels = []): ?float
    {
        return $this->samples[$name][self::key($labels)] ?? null;
    }

    /**
     * Every sample of a name: labels key => value.
     *
     * @return array<string, float>
     */
    public function series(string $name): array
    {
        return $this->samples[$name] ?? [];
    }

    /**
     * Families with a TYPE line, in exposition order.
     *
     * @return list<string>
     */
    public function families(): array
    {
        return array_keys($this->types);
    }

    /**
     * @param  array<string, string>  $labels
     */
    public static function key(array $labels): string
    {
        ksort($labels);

        return implode(',', array_map(
            static fn (string $name, string $value): string => $name.'="'.addcslashes($value, "\\\"\n").'"',
            array_keys($labels),
            $labels,
        ));
    }

    /**
     * @return array{0: string, 1: array<string, string>, 2: float}
     */
    private static function sample(string $line, int $number): array
    {
        if (preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)(\{.*\})?\s+(\S+)(\s+-?\d+)?$/', $line, $match) !== 1) {
            throw new InvalidArgumentException(sprintf('Line %d is not a sample: %s', $number, $line));
        }

        $labels = ($match[2] ?? '') === '' ? [] : self::labels(substr($match[2], 1, -1), $number);

        return [$match[1], $labels, self::number($match[3], $number)];
    }

    /**
     * @return array<string, string>
     */
    private static function labels(string $text, int $number): array
    {
        $labels = [];
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length) {
            if (preg_match('/\G([a-zA-Z_][a-zA-Z0-9_]*)="/', $text, $match, 0, $offset) !== 1) {
                throw new InvalidArgumentException(sprintf('Line %d: bad label at offset %d.', $number, $offset));
            }

            $offset += strlen($match[0]);
            $value = '';

            while (true) {
                if ($offset >= $length) {
                    throw new InvalidArgumentException(sprintf('Line %d: unterminated label value.', $number));
                }

                $char = $text[$offset];

                if ($char === '"') {
                    $offset++;

                    break;
                }

                if ($char === '\\') {
                    $next = $text[$offset + 1] ?? '';
                    $value .= match ($next) {
                        'n' => "\n",
                        '\\' => '\\',
                        '"' => '"',
                        default => throw new InvalidArgumentException(sprintf('Line %d: bad escape \\%s.', $number, $next)),
                    };
                    $offset += 2;

                    continue;
                }

                $value .= $char;
                $offset++;
            }

            if (isset($labels[$match[1]])) {
                throw new InvalidArgumentException(sprintf('Line %d: duplicate label %s.', $number, $match[1]));
            }

            $labels[$match[1]] = $value;

            if ($offset < $length) {
                if ($text[$offset] !== ',') {
                    throw new InvalidArgumentException(sprintf('Line %d: expected "," at offset %d.', $number, $offset));
                }

                $offset++;
            }
        }

        return $labels;
    }

    private static function number(string $value, int $number): float
    {
        return match ($value) {
            '+Inf' => INF,
            '-Inf' => -INF,
            'NaN' => NAN,
            default => is_numeric($value) ? (float) $value : throw new InvalidArgumentException(sprintf('Line %d: bad value %s.', $number, $value)),
        };
    }
}
