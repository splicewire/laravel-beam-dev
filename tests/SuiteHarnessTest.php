<?php

namespace Splicewire\Beam\Dev\Tests;

use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Dev\Databases\SuiteHarness;

/**
 * The second half of the 2026-08-27 defect, pinned.
 *
 * {@see InMemoryConnectionTest} covers the half that was already visible — a scratch database
 * reported on a connection that cannot hold one. This covers the half that survived it, and it is the
 * half that actually cost the time: the command produced a real, reachable database on a real server
 * and then printed an env line for a variable the target suite does not read.
 *
 * Reproduced in `splicewire/tower`: `env('TEST_DB_DATABASE', 'test_tower')` in
 * `tests/TenantTestCase.php`, `DB_DATABASE=…` on the command's output, and a session that believed
 * it was isolated while sharing `test_tower` with a concurrent flagship run — 48 failures that were
 * 2 once the database was genuinely its own.
 *
 * The fixture under `tests/fixtures/testbench-project` is that harness reduced to the two lines the
 * scan reads. It is never autoloaded; it is a file to be read, which is the whole point.
 */
class SuiteHarnessTest extends TestCase
{
    private function fixtureRoot(): string
    {
        return __DIR__.'/fixtures/testbench-project';
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('beam.dev.harness_paths', [$this->fixtureRoot()]);
    }

    public function test_it_reads_the_env_var_the_harness_actually_selects_its_database_with(): void
    {
        $harness = $this->app->make(SuiteHarness::class);

        $this->assertSame(['TEST_DB_DATABASE'], $harness->envVars());
        $this->assertSame(['pgsql'], $harness->drivers());
        $this->assertSame(['tests/TenantTestCase.php'], $harness->sources());
    }

    /**
     * The union, not a choice. A variable the suite ignores costs nothing; a variable the suite reads
     * and nobody set is the entire defect.
     */
    public function test_the_emitted_list_unions_configuration_with_what_the_harness_reads(): void
    {
        $harness = $this->app->make(SuiteHarness::class);

        $this->assertSame(['DB_DATABASE', 'TEST_DB_DATABASE'], $harness->targetEnvVars());
        $this->assertSame(['TEST_DB_DATABASE'], $harness->undeclaredEnvVars());
    }

    /**
     * The exact tower invocation, minus the server: the command must emit `TEST_DB_DATABASE` even
     * though nothing in `config('beam.dev.env')` mentions it, and must name the file it learned that
     * from so the claim is checkable.
     */
    public function test_the_command_emits_the_discovered_variable_and_says_where_it_came_from(): void
    {
        Artisan::call('splicewire:beam:dev:isolated-test-db', [
            '--slug' => 'tower1',
            '--any-driver' => true,
        ]);

        $output = Artisan::output();

        $this->assertStringContainsString('TEST_DB_DATABASE=', $output);
        $this->assertStringContainsString('DB_DATABASE=', $output);
        $this->assertStringContainsString('tests/TenantTestCase.php', $output);
    }

    /**
     * The refusal. Pointed at a SQLite connection while the harness pins pgsql, the command must stop
     * rather than hand back an env line that would isolate nothing — this repo's own default
     * connection under a package testbench is exactly that shape.
     */
    public function test_it_refuses_a_driver_the_harness_will_never_open(): void
    {
        $this->assertSame(1, Artisan::call('splicewire:beam:dev:isolated-test-db', ['--slug' => 'nope']));

        $output = Artisan::output();

        $this->assertStringContainsString('pgsql', $output);
        $this->assertStringContainsString('tests/TenantTestCase.php', $output);
        $this->assertStringNotContainsString('Created', $output);
    }

    public function test_the_refusal_is_escapable_when_the_sqlite_suite_really_is_the_target(): void
    {
        $this->assertSame(0, Artisan::call('splicewire:beam:dev:isolated-test-db', [
            '--slug' => 'yes',
            '--any-driver' => true,
        ]));
    }

    /**
     * Absence of evidence is not evidence: a project with no readable harness falls back to the
     * configured list, and SAYS it is doing that rather than presenting the fallback as a finding.
     */
    public function test_no_harness_falls_back_to_configuration_out_loud(): void
    {
        config()->set('beam.dev.harness_paths', []);
        $this->app->forgetInstance(SuiteHarness::class);

        Artisan::call('splicewire:beam:dev:isolated-test-db', ['--slug' => 'bare']);
        $output = Artisan::output();

        $this->assertStringContainsString('No test-harness files were found', $output);
        $this->assertStringContainsString('DB_DATABASE=', $output);
        $this->assertStringNotContainsString('TEST_DB_DATABASE=', $output);
    }
}
