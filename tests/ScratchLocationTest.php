<?php

namespace Splicewire\Beam\Dev\Tests;

use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;

/**
 * A scratch database must never appear in the consumer's repo.
 *
 * The old rule put SQLite scratch files in `dirname($configured)` — the directory holding the
 * connection's own database. On `:memory:` that is the process's working directory, which is how a
 * stray `test_k2uauqv7.sqlite` came to sit in `splicewire/tower`'s repo root; {@see IsolationGuard}
 * now refuses that connection, but the rule was wrong beyond that one case. A project with a real
 * `database/database.sqlite` had every isolated run drop files into a tracked directory.
 *
 * This test does not use the pinned `beam.dev.sqlite_dir` the rest of the suite sets, because the
 * value under test IS the default.
 */
class ScratchLocationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Drop the harness's pin so the package default is what answers.
        $app['config']->set('beam.dev.sqlite_dir', null);
    }

    public function test_scratch_files_do_not_land_beside_the_connections_own_database(): void
    {
        $databases = $this->app->make(ScratchDatabases::class);

        $this->assertSame(0, Artisan::call('splicewire:beam:dev:isolated-test-db', ['--slug' => 'elsewhere']));

        $configuredDir = dirname((string) config('database.connections.scratch.database'));

        $this->assertFileDoesNotExist($configuredDir.'/test_elsewhere.sqlite');
        $this->assertFileDoesNotExist(getcwd().'/test_elsewhere.sqlite');
        $this->assertTrue($databases->exists('test_elsewhere', 'scratch'));

        // The emitted value is a real, absolute path outside the repo — and the command's own
        // reachability probe already opened it, so this asserts where rather than whether.
        $this->assertStringContainsString(sys_get_temp_dir(), $databases->targetValue('test_elsewhere', 'scratch'));

        $this->assertTrue($databases->drop('test_elsewhere', 'scratch'));
    }

    /**
     * The directory is stable rather than pid-keyed on purpose: the drop runs in a different process
     * than the create. What is session-keyed is the NAME. A pid-keyed directory would be a scratch
     * database nothing could ever reap.
     */
    public function test_the_scratch_directory_is_reachable_from_a_second_resolution(): void
    {
        $first = $this->app->make(ScratchDatabases::class);
        $first->create('test_stable', 'scratch');

        $this->app->forgetInstance(ScratchDatabases::class);
        $second = $this->app->make(ScratchDatabases::class);

        $this->assertTrue($second->exists('test_stable', 'scratch'));
        $this->assertTrue($second->drop('test_stable', 'scratch'));
    }
}
