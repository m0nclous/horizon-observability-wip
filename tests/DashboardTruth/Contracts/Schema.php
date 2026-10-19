<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Contracts;

use RuntimeException;

/**
 * Validates the artifacts that pass between the stages against their JSON schemas (`schema/*.schema.json`: plan,
 * observation, recording, manifest), so a stage that writes a shape the next one does not read fails where it writes.
 *
 * A small validator of the JSON Schema 2020-12 keywords the schemas use (no dependency): `type` (one or a list),
 * `properties`, `required`, `additionalProperties` (boolean or schema), `items`, `enum`, `const`, `minimum`,
 * `minItems`, `pattern` and local `$ref`s to `#/$defs/<name>`. A decoded JSON object is a PHP array with string keys
 * (or a stdClass); a list is a PHP list.
 */
final class Schema
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $schemas = [];

    /**
     * @throws RuntimeException naming every violation
     */
    public static function assert(string $name, mixed $data, string $where = ''): void
    {
        $violations = self::violations($name, $data);

        if ($violations !== []) {
            throw new RuntimeException(sprintf("%s does not match the %s schema:\n- %s", $where === '' ? 'The data' : $where, $name, implode("\n- ", array_slice($violations, 0, 20))));
        }
    }

    /**
     * @return list<string>
     */
    public static function violations(string $name, mixed $data): array
    {
        $schema = self::schema($name);
        $violations = [];
        self::check($schema, self::normalize($data), '$', $schema, $violations);

        return $violations;
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(string $name): array
    {
        if (! isset(self::$schemas[$name])) {
            $path = __DIR__.'/schema/'.$name.'.schema.json';
            $schema = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

            if (! is_array($schema)) {
                throw new RuntimeException('No JSON schema '.$path.'.');
            }

            /** @var array<string, mixed> $schema */
            self::$schemas[$name] = $schema;
        }

        return self::$schemas[$name];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $root
     * @param  list<string>  $violations
     */
    private static function check(array $schema, mixed $data, string $path, array $root, array &$violations): void
    {
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $name = substr($schema['$ref'], strlen('#/$defs/'));
            $target = $root['$defs'][$name] ?? null;

            if (! is_array($target)) {
                $violations[] = $path.': unknown $ref '.$schema['$ref'];

                return;
            }

            /** @var array<string, mixed> $target */
            self::check($target, $data, $path, $root, $violations);

            return;
        }

        if (isset($schema['type'])) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];

            // A decoded empty object is an empty PHP array: it matches both
            $matches = in_array(self::type($data), $types, true)
                || (self::type($data) === 'integer' && in_array('number', $types, true))
                || ($data === [] && in_array('object', $types, true));

            if (! $matches) {
                $violations[] = sprintf('%s: %s instead of %s', $path, self::type($data), implode('|', array_map(strval(...), $types)));

                return;
            }
        }

        if (array_key_exists('const', $schema) && $data !== $schema['const']) {
            $violations[] = sprintf('%s: not %s', $path, json_encode($schema['const']));
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            $violations[] = sprintf('%s: %s is not one of %s', $path, json_encode($data), json_encode($schema['enum']));
        }

        if (isset($schema['minimum']) && is_numeric($schema['minimum']) && (is_int($data) || is_float($data)) && $data < $schema['minimum']) {
            $violations[] = sprintf('%s: %s is below %s', $path, $data, $schema['minimum']);
        }

        if (isset($schema['pattern']) && is_string($schema['pattern']) && is_string($data) && preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/u', $data) !== 1) {
            $violations[] = sprintf('%s: "%s" does not match /%s/', $path, mb_strimwidth($data, 0, 60, '...'), $schema['pattern']);
        }

        if ($data === [] && is_array($schema['required'] ?? null) && $schema['required'] !== []) {
            $violations[] = sprintf('%s: empty, missing "%s"', $path, implode('", "', array_map(strval(...), $schema['required'])));

            return;
        }

        if (is_array($data) && self::type($data) === 'array') {
            if (isset($schema['minItems']) && is_int($schema['minItems']) && count($data) < $schema['minItems']) {
                $violations[] = sprintf('%s: %d items, at least %d', $path, count($data), $schema['minItems']);
            }

            if (isset($schema['items']) && is_array($schema['items'])) {
                /** @var array<string, mixed> $items */
                $items = $schema['items'];

                foreach ($data as $index => $item) {
                    self::check($items, $item, $path.'['.$index.']', $root, $violations);
                }
            }

            return;
        }

        if (self::type($data) !== 'object' || ! is_array($data)) {
            return;
        }

        foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $required) {
            if (! array_key_exists((string) $required, $data)) {
                $violations[] = sprintf('%s: missing "%s"', $path, $required);
            }
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ($data as $key => $value) {
            $key = (string) $key;

            if (isset($properties[$key]) && is_array($properties[$key])) {
                /** @var array<string, mixed> $property */
                $property = $properties[$key];
                self::check($property, $value, $path.'.'.$key, $root, $violations);
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                $violations[] = sprintf('%s: unexpected "%s"', $path, $key);
            } elseif (is_array($schema['additionalProperties'] ?? null)) {
                /** @var array<string, mixed> $additional */
                $additional = $schema['additionalProperties'];
                self::check($additional, $value, $path.'.'.$key, $root, $violations);
            }
        }
    }

    private static function type(mixed $data): string
    {
        return match (true) {
            $data === null => 'null',
            is_bool($data) => 'boolean',
            is_int($data) => 'integer',
            is_float($data) => 'number',
            is_string($data) => 'string',
            is_array($data) && array_is_list($data) => 'array',
            is_array($data) => 'object',
            default => 'unknown',
        };
    }

    /**
     * Objects (e.g. `(object) []` for an empty JSON object) to arrays.
     */
    private static function normalize(mixed $data): mixed
    {
        if (is_object($data)) {
            return array_map(self::normalize(...), get_object_vars($data));
        }

        if (is_array($data)) {
            return array_map(self::normalize(...), $data);
        }

        return $data;
    }
}
