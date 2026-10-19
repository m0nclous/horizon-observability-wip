<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Record;

use DF\HorizonObservability\Tests\Support\RedisKeys;
use InvalidArgumentException;

/**
 * The kept Redis worlds (design section 3): a dataset's Redis keys live under `ho_t17_<run>_<dataset>:` and its
 * batches in `<run dir>/datasets/<dataset>/<dataset>.sqlite`, from recording to capture. bin/run deletes them at the
 * end of the run from its shutdown and signal handlers, so this also happens on a failure; `--keep` keeps them for a
 * local re-capture. Only keys under a world prefix are ever deleted (SCAN + DEL, never FLUSHDB), so the shared Redis
 * stays safe.
 */
final class WorldKeeper
{
    private const RUN = '/^[a-z0-9]{4,20}$/';

    private const DATASET = '/^[a-z][a-z0-9-]{0,30}$/';

    /**
     * The Redis prefix of a world: REDIS_PREFIX of the recorder and the router; Horizon's prefix appends `horizon:`.
     */
    public static function prefix(string $run, string $dataset): string
    {
        if (preg_match(self::RUN, $run) !== 1 || preg_match(self::DATASET, $dataset) !== 1) {
            throw new InvalidArgumentException(sprintf('Bad run "%s" or dataset "%s".', $run, $dataset));
        }

        return 'ho_t17_'.$run.'_'.$dataset.':';
    }

    public static function directory(string $runDir, string $dataset): string
    {
        return rtrim($runDir, '/').'/datasets/'.$dataset;
    }

    public static function sqlite(string $runDir, string $dataset): string
    {
        return self::directory($runDir, $dataset).'/'.$dataset.'.sqlite';
    }

    /**
     * Delete a world: its Redis keys and its SQLite database. The number of keys deleted.
     */
    public static function delete(string $prefix, ?string $sqlite = null): int
    {
        if (preg_match('/^ho_t17_[a-z0-9]+_[a-z0-9-]+:$/', $prefix) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a world prefix.', $prefix));
        }

        $deleted = RedisKeys::delete($prefix);

        if ($sqlite !== null && is_file($sqlite)) {
            unlink($sqlite);
        }

        return $deleted;
    }

    /**
     * Delete every world of a run (all datasets): the keys under `ho_t17_<run>_`.
     */
    public static function deleteRun(string $run): int
    {
        if (preg_match(self::RUN, $run) !== 1) {
            throw new InvalidArgumentException(sprintf('Bad run "%s".', $run));
        }

        return RedisKeys::delete('ho_t17_'.$run.'_');
    }
}
