<?php

namespace Splicewire\Beam\Dev\Tests;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;
use Splicewire\Beam\Dev\Databases\ServerConnection;

/**
 * "It reported success" and "it did the work" are different claims, and this package's whole job is
 * that the second one holds. So the create path asserts the database is REACHABLE before it prints
 * that it made one — the check that turns this defect class from silent into loud.
 */
class ReachabilityTest extends TestCase
{
    public function test_a_created_database_is_reachable(): void
    {
        $databases = $this->app->make(ScratchDatabases::class);
        $databases->create('test_reach', 'scratch');

        $databases->assertReachable('test_reach', 'scratch');

        $this->assertTrue(true); // No exception is the assertion.
    }

    public function test_asserting_reachability_of_a_database_that_was_never_made_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not reachable/');

        $this->app->make(ScratchDatabases::class)->assertReachable('test_absent', 'scratch');
    }

    /**
     * The exact failure the ticket describes, forced: a create that returns true having made nothing.
     * The command must report the lie, not repeat it.
     */
    public function test_the_command_fails_when_create_reports_a_database_it_did_not_make(): void
    {
        $this->app->instance(ScratchDatabases::class, new class($this->app->make(ServerConnection::class), $this->app->make('config')) extends ScratchDatabases
        {
            public function create(string $name, string $connection): bool
            {
                return true; // Claims to have created it; creates nothing.
            }
        });

        $exit = Artisan::call('splicewire:beam:dev:isolated-test-db', ['--slug' => 'phantom']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not reachable', $output);
        $this->assertStringNotContainsString('Run your suite against it', $output);
    }

    /**
     * On SQLite the env value has to be the PATH — a bare name points at nothing, which is the same
     * silent no-isolation failure one layer down.
     */
    public function test_the_emitted_env_points_at_something_that_exists(): void
    {
        Artisan::call('splicewire:beam:dev:isolated-test-db', ['--slug' => 'envpath']);
        $output = Artisan::output();

        preg_match('/DB_DATABASE=(\S+)/', $output, $m);

        $this->assertNotEmpty($m, 'No DB_DATABASE assignment was emitted.');
        $this->assertFileExists($m[1]);
    }
}
