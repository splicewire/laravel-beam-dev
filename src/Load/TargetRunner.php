<?php

namespace Splicewire\Beam\Dev\Load;

use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Runs one {@see LoadTarget} N times in this process, timing each run and counting the queries it
 * issued.
 *
 * This is the whole measurement primitive. Concurrency is NOT its job — {@see
 * \Splicewire\Beam\Dev\Console\LoadCommand} gets that by running several of these in separate
 * processes, because PHP has no in-process concurrency worth measuring against and a threaded
 * simulation would produce numbers that describe nothing.
 *
 * ## Query counting
 *
 * Via `DatabaseManager::listen()`, which fires per executed query on every connection. Measured
 * 2026-09-04: `DB::listen` had **zero** uses across every package `src` and every Herd `app` directory
 * (controls: 52 and 72 files containing `DB::`), so nothing else in the estate counted queries at all.
 *
 * The listener is registered once and filtered by a run cursor rather than added and removed per
 * iteration: Laravel's query listeners cannot be individually detached, so a
 * register-per-iteration loop would accumulate N listeners and count the Nth query N times. That bug
 * inflates smoothly with sample size and looks exactly like a real superlinearity.
 */
class TargetRunner
{
    private int $queriesThisRun = 0;

    private bool $listening = false;

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @param  int  $iterations  how many times to run the target in this process
     * @return list<Sample>
     */
    public function run(LoadTarget $target, int $iterations): array
    {
        $this->listen();

        $samples = [];

        for ($i = 0; $i < $iterations; $i++) {
            $this->queriesThisRun = 0;
            $error = null;

            // hrtime(true) is a monotonic integer nanosecond counter — immune to clock adjustment
            // mid-run, and to the float-resolution decay microtime() suffers as the epoch grows.
            $started = hrtime(true);

            try {
                $target->run();
            } catch (Throwable $e) {
                // Recorded, never rethrown: the error RATE is one of the two things a load run is
                // for, and aborting on the first failure would throw away the reading that matters
                // most — what happens as the system degrades.
                $error = $e::class.': '.$e->getMessage();
            }

            $samples[] = new Sample(
                durationNs: hrtime(true) - $started,
                queries: $this->queriesThisRun,
                error: $error,
            );
        }

        return $samples;
    }

    private function listen(): void
    {
        if ($this->listening) {
            return;
        }

        $this->db->listen(function (): void {
            $this->queriesThisRun++;
        });

        $this->listening = true;
    }
}
