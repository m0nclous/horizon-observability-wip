<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Truth\Support;

use DF\HorizonObservability\Tests\TestCase;

/**
 * The shared scenarios of the truth tests, each with its timeline and its outcomes known in advance.
 */
final class Scenarios
{
    /**
     * Seconds after the last attempt at which the attempt counters are compared: past the 75 s hold of new series.
     */
    public const SETTLED = 80;

    /**
     * Every attempt outcome on four queues, on the scenario clock T (whole seconds):
     *
     * | at     | queue   | job_class       | plan (tries)                    | attempts                                   |
     * |--------|---------|-----------------|---------------------------------|--------------------------------------------|
     * | T+5    | default | Truth\Invoice   | complete x3                     | 3 completed, 0 s                           |
     * | T+5    | default | Truth\Notice    | fail                            | 1 failed, 0 s                              |
     * | T+5    | default | Truth\Sync      | release:0, complete (3)         | 1 released, 1 completed, 0 s               |
     * | T+5    | default | Truth\Exhaust   | release:0 (1)                   | 1 released 0 s, 1 failed without duration  |
     * | T+5    | reports | Truth\Report    | throw, complete (2), 0.4 s      | 1 retried, 1 completed, 0.4 s each         |
     * | T+5    | slow    | Truth\Export    | throw, throw (2)                | 1 retried, 1 failed, 0 s                   |
     * | T+5    | slow    | Truth\Backoff   | throw, complete (2), backoff 30 | 1 retried at T+5, 1 completed at T+40      |
     * | T+5    | sms     | Truth\Hang      | hang, timeout 1 s (1)           | 1 timed_out, exactly 1 s (forked worker)   |
     * | T+5    | mail    | Truth\Long      | complete, 1.5 s                 | 1 completed (reserved during a scrape)     |
     * | T+40   | reports | Truth\Later     | complete, dispatched +20 s      | 1 completed, waited from T+20              |
     *
     * Returns the scenario at T+40 + {@see self::SETTLED}; `$atReserved` is called while Truth\Long is reserved
     * (at T+5, after the other T+5 attempts).
     *
     * @param  (callable(Scenario): void)|null  $atReserved
     */
    public static function attempts(TestCase $test, ?callable $atReserved = null): Scenario
    {
        $scenario = Scenario::start($test);

        foreach (range(1, 3) as $ignored) {
            $scenario->dispatch(new TruthJob('Truth\\Invoice'));
        }

        $scenario->dispatch(new TruthJob('Truth\\Notice', ['fail']))
            ->dispatch(new TruthJob('Truth\\Sync', ['release:0', 'complete'], tries: 3))
            ->dispatch(new TruthJob('Truth\\Exhaust', ['release:0'], tries: 1))
            ->dispatch(new TruthJob('Truth\\Report', ['throw', 'complete'], tries: 2, sleepMs: 400), 'reports')
            ->dispatch(new TruthJob('Truth\\Export', ['throw', 'throw'], tries: 2), 'slow')
            ->dispatch(new TruthJob('Truth\\Backoff', ['throw', 'complete'], tries: 2, backoff: 30), 'slow')
            ->dispatch(new TruthJob('Truth\\Hang', ['hang'], tries: 1, timeout: 1), 'sms')
            ->dispatch(new TruthJob('Truth\\Long', ['complete'], sleepMs: 1500), 'mail')
            ->dispatch(new TruthJob('Truth\\Later'), 'reports', delay: 20);

        $scenario->advance(5)
            ->work('default')
            ->work('reports')
            ->work('slow')
            ->timeOutInChild(1.0, 'sms');

        $scenario->whileReserved(static function () use ($scenario, $atReserved): void {
            if ($atReserved !== null) {
                $atReserved($scenario);
            }
        }, 'mail');

        $scenario->advance(35)
            ->work('slow')
            ->work('reports')
            ->advance(self::SETTLED);

        return $scenario;
    }
}
