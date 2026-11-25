<?php

namespace Splicewire\Beam\Dev\Tests;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;

/**
 * drop-db drops, by exact name on a real Postgres server, a test database this process did not create (ticket
 * owner-review-20261112 74). The database is made by beam-dev's own `isolated-test-db`, the one sanctioned way to
 * create one, and removed only through `drop-db`.
 *
 * Runs only against a server named by BEAM_DEV_TEST_PGSQL_HOST (with _PORT, _USERNAME and _PASSWORD), and is skipped
 * without it: the rest of this suite never needs a server.
 */
class ExternalPostgresDropTest extends TestCase
{
    private string $name;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Only the suite's sqlite default and this server: the testbench's own pgsql/mysql entries would otherwise be
        // probed by the miss hint and could answer for the same local server under another name.
        $app['config']->set('database.connections', Arr::only($app['config']->get('database.connections'), ['scratch']));
        $app['config']->set('database.connections.pg_real', [
            'driver' => 'pgsql',
            'host' => (string) getenv('BEAM_DEV_TEST_PGSQL_HOST'),
            'port' => (int) (getenv('BEAM_DEV_TEST_PGSQL_PORT') ?: 5432),
            'username' => (string) getenv('BEAM_DEV_TEST_PGSQL_USERNAME'),
            'password' => (string) getenv('BEAM_DEV_TEST_PGSQL_PASSWORD'),
            'database' => 'postgres',
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ((string) getenv('BEAM_DEV_TEST_PGSQL_HOST') === '') {
            $this->markTestSkipped('Set BEAM_DEV_TEST_PGSQL_HOST (and _PORT, _USERNAME, _PASSWORD) to run against a real Postgres.');
        }

        $this->name = 'test_beamdev_external_'.getmypid().'_'.bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        // Leave nothing behind if an assertion failed between the create and the drop. Through the API drop-db
        // itself calls, not the command: after a failed `$this->artisan()` expectation the console output is still
        // the test's mock, and a command run here would die quietly and leave the database behind.
        $databases = isset($this->name) ? $this->app?->make(ScratchDatabases::class) : null;
        if ($databases?->exists($this->name, 'pg_real')) {
            $databases->drop($this->name, 'pg_real');
        }

        parent::tearDown();
    }

    public function test_an_externally_created_database_is_dropped_by_exact_name_on_the_configured_server(): void
    {
        $databases = $this->app->make(ScratchDatabases::class);

        $status = Artisan::call('splicewire:beam:dev:isolated-test-db', [
            '--connection' => 'pg_real',
            '--name' => $this->name,
            '--no-init' => true,
            '--any-driver' => true,
        ]);
        $this->assertSame(0, $status, Artisan::output());
        $this->assertTrue($databases->exists($this->name, 'pg_real'), 'isolated-test-db should have made it.');

        // Asked on the wrong connection (the suite's sqlite default), it is not dropped, and the miss names the
        // connection whose server holds it.
        $this->artisan('splicewire:beam:dev:drop-db', ['names' => [$this->name], '--keep-workers' => true])
            ->expectsOutputToContain('It does exist on connection [pg_real] (pgsql): pass --connection=pg_real.')
            ->assertSuccessful();
        $this->assertTrue($databases->exists($this->name, 'pg_real'));

        $this->artisan('splicewire:beam:dev:drop-db', ['names' => [$this->name], '--connection' => 'pg_real', '--keep-workers' => true])
            ->expectsOutputToContain("Dropped {$this->name} on connection [pg_real] (pgsql)")
            ->assertSuccessful();
        $this->assertFalse($databases->exists($this->name, 'pg_real'));
        $this->assertSame([], $databases->names('pg_real', $this->name));
    }
}
