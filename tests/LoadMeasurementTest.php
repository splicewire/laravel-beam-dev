<?php

namespace Splicewire\Beam\Dev\Tests;

use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Dev\Load\LoadTarget;
use Splicewire\Beam\Dev\Load\LoadTargetSource;
use Splicewire\Beam\Dev\Load\Sample;
use Splicewire\Beam\Dev\Load\TargetRunner;
use Splicewire\Beam\Dev\Load\Timings;

/**
 * The load-measurement engine: percentile math, timing resolution, query counting, and the two
 * failures the command must never render as a result.
 */
class LoadMeasurementTest extends TestCase
{
    /**
     * An in-memory SQLite default, because this suite measures the MEASUREMENT and not a database.
     * The package's own {@see TestCase} points `database.default` at a scratch sqlite FILE, which is
     * right for the commands that create and reap databases and wrong here — the file does not exist
     * until something provisions it, so a query against it throws.
     *
     * Worth stating because it is how the query-counting test first failed: `queries` read 0, which
     * looked like a listener that never fired, and was in fact a target whose query threw and was
     * faithfully recorded as an error. The count was honest; the assertion was aimed at the wrong
     * thing. Hence `assertNull($sample->error)` below, checked BEFORE the count.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'load_testing');
        $app['config']->set('database.connections.load_testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /** Nearest-rank, against a set whose answers can be checked by hand. */
    public function test_it_computes_percentiles_by_nearest_rank(): void
    {
        // 10 samples, 1ms … 10ms. Nearest rank for p is ceil(p/100 * 10).
        $timings = Timings::of(array_map(
            fn (int $ms): Sample => new Sample($ms * 1_000_000, 1),
            range(1, 10),
        ));

        $this->assertSame(5.0, $timings->percentileMs(50));  // rank 5  → 5ms
        $this->assertSame(10.0, $timings->percentileMs(95)); // rank 10 → 10ms
        $this->assertSame(10.0, $timings->percentileMs(99)); // rank 10 → 10ms
        $this->assertSame(1.0, $timings->percentileMs(1));   // rank 1  → 1ms
    }

    /**
     * Every percentile it reports must be a value some run actually took. This is the property that
     * distinguishes nearest-rank from an interpolating method, and it is why the method is named in
     * the output rather than left for the reader to assume.
     */
    public function test_every_percentile_is_an_observed_value(): void
    {
        $observed = [3, 17, 42, 99, 1000];
        $timings = Timings::of(array_map(fn (int $ns): Sample => new Sample($ns, 0), $observed));

        foreach ([1, 25, 50, 75, 95, 99, 100] as $p) {
            $this->assertContains($timings->percentileNs($p), $observed);
        }
    }

    /**
     * ⚠️ The criterion this package exists downstream of. `rushing/laravel-request-logs` declares
     * `timestamp('request_at')` at precision 0 while exposing a `duration_ms` accessor, so it returns
     * milliseconds that are always multiples of 1000 and never looks broken. An engine that could do
     * the same would be worse than no engine.
     */
    public function test_timing_resolution_is_sub_millisecond(): void
    {
        $runner = $this->app->make(TargetRunner::class);

        $samples = $runner->run(new NoopTarget, 20);

        $distinct = array_unique(array_map(fn (Sample $s): int => $s->durationNs, $samples));

        // A no-op takes well under a millisecond, so a millisecond-resolution clock would report every
        // sample as 0 (or as an identical multiple). Both a non-zero reading and genuine variance are
        // required — either alone could be produced by a broken clock.
        $this->assertGreaterThan(0, max(array_map(fn (Sample $s): int => $s->durationNs, $samples)));
        $this->assertGreaterThan(1, count($distinct), 'Every sample was identical — the clock is not resolving these runs.');
    }

    public function test_it_counts_queries_per_run_without_accumulating_listeners(): void
    {
        $runner = $this->app->make(TargetRunner::class);

        $samples = $runner->run(new QueryingTarget, 5);

        // Two queries every time. A listener registered per iteration would make these 2, 4, 6, 8, 10 —
        // a smooth inflation that reads as real superlinearity rather than as a harness defect.
        foreach ($samples as $sample) {
            // Error first. A throwing target records 0 queries perfectly honestly, so asserting the
            // count alone cannot tell "the listener never fired" from "the query never ran".
            $this->assertNull($sample->error);
            $this->assertSame(2, $sample->queries);
        }
    }

    public function test_a_throwing_target_is_recorded_not_fatal_and_excluded_from_latency(): void
    {
        $runner = $this->app->make(TargetRunner::class);

        $samples = $runner->run(new ThrowingTarget, 3);
        $timings = Timings::of($samples);

        $this->assertSame(3, $timings->count());
        $this->assertSame(3, $timings->errorCount());
        $this->assertSame(1.0, $timings->errorRate());

        // Null, never 0. A failing run is usually fast, so folding errors into latency makes a
        // degrading system look like it is speeding up — and a 0 would read as "instant".
        $this->assertNull($timings->percentileMs(95));
    }

    /**
     * ⚠️ The estate's signature defect, guarded directly: "no adapter installed" must not render the
     * same as "this host declares no operations".
     */
    public function test_an_unbound_target_source_fails_rather_than_reporting_an_empty_run(): void
    {
        $this->artisan('splicewire:beam:dev:load')
            ->assertFailed()
            ->expectsOutputToContain('No '.LoadTargetSource::class.' is bound.');
    }

    public function test_a_bound_source_declaring_nothing_succeeds_and_says_so(): void
    {
        $this->app->bind(LoadTargetSource::class, fn (): LoadTargetSource => new EmptySource);

        $this->artisan('splicewire:beam:dev:load')
            ->assertSuccessful()
            ->expectsOutputToContain('declares no targets');
    }

    public function test_it_lists_declared_targets_when_given_no_argument(): void
    {
        $this->app->bind(LoadTargetSource::class, fn (): LoadTargetSource => new NoopSource);

        $this->artisan('splicewire:beam:dev:load')
            ->assertSuccessful()
            ->expectsOutputToContain('noop');
    }

    public function test_it_refuses_a_target_label_the_host_does_not_declare(): void
    {
        $this->app->bind(LoadTargetSource::class, fn (): LoadTargetSource => new NoopSource);

        $this->artisan('splicewire:beam:dev:load', ['target' => 'does-not-exist'])
            ->assertFailed()
            ->expectsOutputToContain("No target labelled 'does-not-exist'");
    }
}

class NoopTarget implements LoadTarget
{
    public function label(): string
    {
        return 'noop';
    }

    public function run(): void {}
}

class QueryingTarget implements LoadTarget
{
    public function label(): string
    {
        return 'querying';
    }

    public function run(): void
    {
        DB::select('select 1');
        DB::select('select 2');
    }
}

class ThrowingTarget implements LoadTarget
{
    public function label(): string
    {
        return 'throwing';
    }

    public function run(): void
    {
        throw new \RuntimeException('deliberate');
    }
}

class NoopSource implements LoadTargetSource
{
    public function targets(): array
    {
        return ['noop' => new NoopTarget];
    }
}

class EmptySource implements LoadTargetSource
{
    public function targets(): array
    {
        return [];
    }
}
