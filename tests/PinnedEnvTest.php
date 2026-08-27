<?php

namespace Splicewire\Beam\Dev\Tests;

use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;
use Splicewire\Beam\Dev\Databases\SuiteHarness;

/**
 * The third way the printed env line can fail to reach the suite: the project's phpunit config pins
 * the variable itself.
 *
 * The rule is exact, not a heuristic, and it is one line of PHPUnit —
 * `PhpHandler::handleEnvVariables()`:
 *
 *     if ($force || getenv($name) === false) { putenv("{$name}={$value}"); }
 *
 * So a pin carrying `force="true"` discards a shell override unconditionally, and a pin without it
 * cannot touch one. Measured on PHPUnit 12.5.33 with both variables exported: the unforced pin read
 * back `FROM_SHELL`, the forced pin read back `PINNED_IN_XML`.
 *
 * Both halves are load-bearing and the second one is the easier mistake. `~/Herd/audiostud` pins
 * `DB_DATABASE=audiostud_testing` with no `force`, and a shell override demonstrably does reach that
 * suite — refusing there would break a root that works.
 */
class PinnedEnvTest extends TestCase
{
    private function useFixture(string $name): SuiteHarness
    {
        config()->set('beam.dev.harness_paths', [__DIR__.'/fixtures/'.$name]);
        $this->app->forgetInstance(SuiteHarness::class);

        return $this->app->make(SuiteHarness::class);
    }

    public function test_a_forced_pin_is_refused_because_phpunit_will_discard_the_override(): void
    {
        $this->useFixture('pinned-forced');

        $this->assertSame(1, Artisan::call('splicewire:beam:dev:isolated-test-db', [
            '--slug' => 'forced',
            '--any-driver' => true,
        ]));

        $output = Artisan::output();

        $this->assertStringContainsString('force', $output);
        $this->assertStringContainsString('pinned_hard', $output);
        $this->assertStringNotContainsString('Created', $output);
    }

    /**
     * And nothing is left behind by the refusal. A command that creates a database and then explains
     * the suite will not use it has still put a real database on a real server for someone else to
     * wonder about — so the pin check runs before `create()`, not after.
     */
    public function test_the_refusal_creates_nothing(): void
    {
        $databases = $this->app->make(ScratchDatabases::class);
        $this->useFixture('pinned-forced');

        Artisan::call('splicewire:beam:dev:isolated-test-db', ['--slug' => 'forced2', '--any-driver' => true]);

        $this->assertFalse($databases->exists('test_forced2', 'scratch'));
    }

    /**
     * The audiostud shape. An unforced pin loses to the shell, so this must proceed — and must SAY
     * the pin was looked at, because a caller who finds their variable hardcoded and concludes the
     * override cannot work is reasoning correctly from incomplete information.
     */
    public function test_an_unforced_pin_proceeds_and_is_reported_as_losing(): void
    {
        $this->useFixture('pinned-soft');

        $this->assertSame(0, Artisan::call('splicewire:beam:dev:isolated-test-db', [
            '--slug' => 'soft',
            '--any-driver' => true,
        ]));

        $output = Artisan::output();

        $this->assertStringContainsString('Created test_soft', $output);
        $this->assertStringContainsString('audiostud_testing', $output);
        $this->assertStringContainsString('force', $output);
    }

    /**
     * The third outcome, alongside "isolated" and "refused": there was nothing to do. A suite pinned
     * to an in-memory database is isolated by construction, and a scratch database for it would be
     * one nothing ever opens.
     */
    public function test_an_in_memory_suite_is_reported_as_already_isolated_and_nothing_is_created(): void
    {
        $databases = $this->app->make(ScratchDatabases::class);
        $this->useFixture('in-memory');

        $this->assertSame(0, Artisan::call('splicewire:beam:dev:isolated-test-db', ['--slug' => 'nothing']));

        $output = Artisan::output();

        $this->assertStringContainsString('already isolated', $output);
        $this->assertStringNotContainsString('Created', $output);
        $this->assertFalse($databases->exists('test_nothing', 'scratch'));
    }

    public function test_the_pin_parser_reads_force_correctly(): void
    {
        $this->assertTrue($this->useFixture('pinned-forced')->pins()['DB_DATABASE']['force']);
        $this->assertFalse($this->useFixture('pinned-soft')->pins()['DB_DATABASE']['force']);
        $this->assertSame('audiostud_testing', $this->useFixture('pinned-soft')->pins()['DB_DATABASE']['value']);
    }

    /**
     * A parallel run migrates into `<base>_test_<token>` and leaves the base EMPTY. That empty base
     * has already been read once as proof that an override failed, when the data was one name over
     * the whole time — so the command says where to look before the run rather than after.
     */
    public function test_it_says_where_a_parallel_run_will_actually_put_its_data(): void
    {
        Artisan::call('splicewire:beam:dev:isolated-test-db', ['--slug' => 'par']);

        $output = Artisan::output();

        $this->assertStringContainsString('test_par_test_1', $output);
        $this->assertStringContainsString('stays EMPTY', $output);
    }
}
