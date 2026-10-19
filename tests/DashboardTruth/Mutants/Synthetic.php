<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Mutants;

use InvalidArgumentException;

/**
 * Synthetic mutants of a render (design section 11.3): each changes one target of the in-memory dashboard the way a
 * plausible regression would, and must turn at least one named case red. bin/run imports a mutated render under
 * `t17m-<mode>-<mutant>` (`--mutant=<name> --expect-red`). WP1 has the slice's mutant; WP8 adds the rest of the list
 * (dropped job matcher, max by -> sum by, [$__range] -> [1h], ...).
 */
final class Synthetic
{
    /**
     * name => [panel id, refId, search, replacement, the cases it must turn red]
     *
     * @var array<string, array{0: int, 1: string, 2: string, 3: string, 4: list<string>}>
     */
    public const MUTANTS = [
        // #8 A counts retried attempts as completed
        'completed-retried' => [8, 'A', 'status=~"completed"', 'status=~"completed|retried"', ['S01']],
    ];

    /**
     * @param  array<array-key, mixed>  $dashboard
     * @return array<array-key, mixed>
     */
    public static function apply(string $name, array $dashboard): array
    {
        $mutant = self::MUTANTS[$name] ?? throw new InvalidArgumentException(sprintf('Unknown mutant "%s"; known: %s.', $name, implode(', ', array_keys(self::MUTANTS))));
        [$panelId, $refId, $search, $replace] = $mutant;
        $applied = 0;
        $mutate = static function (array $panels) use (&$mutate, $panelId, $refId, $search, $replace, &$applied): array {
            foreach ($panels as &$panel) {
                if (! is_array($panel)) {
                    continue;
                }

                if (is_array($panel['panels'] ?? null)) {
                    $panel['panels'] = $mutate($panel['panels']);
                }

                if (($panel['id'] ?? null) !== $panelId || ! is_array($panel['targets'] ?? null)) {
                    continue;
                }

                foreach ($panel['targets'] as &$target) {
                    if (is_array($target) && ($target['refId'] ?? null) === $refId && is_string($target['expr'] ?? null)) {
                        $target['expr'] = str_replace($search, $replace, $target['expr'], $count);
                        $applied += $count;
                    }
                }

                unset($target);
            }

            unset($panel);

            return $panels;
        };
        $dashboard['panels'] = $mutate(is_array($dashboard['panels'] ?? null) ? $dashboard['panels'] : []);

        if ($applied === 0) {
            throw new InvalidArgumentException(sprintf('The mutant "%s" does not apply: #%d %s has no "%s".', $name, $panelId, $refId, $search));
        }

        return $dashboard;
    }
}
