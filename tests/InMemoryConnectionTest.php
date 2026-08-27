<?php

namespace Splicewire\Beam\Dev\Tests;

use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Dev\Databases\IsolationGuard;

/**
 * The harness a package's own testbench gives you: `database.default` is an in-memory SQLite
 * connection. Nothing can be isolated there — there is no server and no file — so both commands must
 * refuse rather than report work they did not do.
 *
 * The defect this pins, measured 2026-08-27: `isolated-test-db` printed "Created test_k2uauqv7 on
 * connection testing", touched a stray `test_k2uauqv7.sqlite` in the CURRENT WORKING DIRECTORY
 * (because `dirname(':memory:')` is `.`), and emitted `DB_DATABASE=test_k2uauqv7` — a value pointing
 * at no database at all. A session following AGENTS.md got a success message and zero isolation.
 */
class InMemoryConnectionTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'database' => 'app',
        ]);

        $app['config']->set('beam.dev.prefix', 'test_');
        $app['config']->set('beam.dev.env', ['DB_DATABASE']);

        // Discovery off: this test is about the CONNECTION guard, and leaving the scan pointed at
        // beam-dev's own tests/ would let a second, unrelated refusal answer first.
        $app['config']->set('beam.dev.harness_paths', []);
    }

    public function test_creating_against_an_in_memory_connection_is_refused(): void
    {
        Artisan::call('splicewire:beam:dev:isolated-test-db', ['--slug' => 'k2uauqv7']);
        $output = Artisan::output();

        $this->assertStringNotContainsString('Created', $output);
        $this->assertStringContainsString('in-memory', $output);
        $this->assertSame(1, Artisan::call('splicewire:beam:dev:isolated-test-db', ['--slug' => 'k2uauqv7']));
    }

    /**
     * A refusal that does not say what to pass instead is a refusal the caller works around.
     */
    public function test_the_refusal_names_a_connection_that_would_work(): void
    {
        Artisan::call('splicewire:beam:dev:isolated-test-db', ['--slug' => 'k2uauqv7']);

        $this->assertStringContainsString('--connection=pgsql', Artisan::output());
    }

    /**
     * The stray file is the visible half of the defect: `dirname(':memory:')` is the process's cwd,
     * so a "created" scratch database landed in whatever directory the command was run from.
     */
    public function test_nothing_is_written_to_the_working_directory(): void
    {
        Artisan::call('splicewire:beam:dev:isolated-test-db', ['--slug' => 'k2uauqv7']);

        $this->assertFileDoesNotExist(getcwd().'/test_k2uauqv7.sqlite');
    }

    /**
     * `--all` on an in-memory connection resolves its file glob against the cwd, so a sweep there
     * would delete prefix-matching files out of whatever directory you happened to be standing in.
     */
    public function test_dropping_against_an_in_memory_connection_is_refused(): void
    {
        $this->assertSame(1, Artisan::call('splicewire:beam:dev:drop-db', ['--all' => true]));
        $this->assertStringContainsString('in-memory', Artisan::output());
    }

    public function test_an_explicit_server_connection_is_still_accepted_past_the_guard(): void
    {
        // No server is running for [pgsql] here, so this must fail on the CONNECTION, never on the
        // in-memory guard — the guard is about the connection you named, not about the app default.
        Artisan::call('splicewire:beam:dev:isolated-test-db', ['--slug' => 'x', '--connection' => 'pgsql']);

        $this->assertStringNotContainsString('in-memory', Artisan::output());
    }

    public function test_the_in_memory_predicate_is_engine_aware(): void
    {
        $guard = $this->app->make(IsolationGuard::class);

        $this->assertTrue($guard->isInMemory('testing'));
        $this->assertFalse($guard->isInMemory('pgsql'));
        $this->assertNull($guard->refuse('pgsql'));
    }
}
