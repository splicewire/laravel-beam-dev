<?php

namespace Splicewire\Beam\Dev\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Splicewire\Beam\Dev\BeamDevServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BeamDevServiceProvider::class];
    }

    /**
     * SQLite on a temp directory is the default harness: it exercises the real create/list/drop paths
     * without needing a database server in CI. The engine-specific SQL for pgsql/mysql is asserted
     * separately, by inspection rather than execution.
     */
    protected function defineEnvironment($app): void
    {
        $dir = sys_get_temp_dir().'/beam-dev-tests';
        @mkdir($dir, 0755, true);

        $app['config']->set('database.default', 'scratch');
        $app['config']->set('database.connections.scratch', [
            'driver' => 'sqlite',
            'database' => $dir.'/primary.sqlite',
            'prefix' => '',
        ]);

        $app['config']->set('beam.dev.prefix', 'test_');
        $app['config']->set('beam.dev.env', ['DB_DATABASE']);
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir().'/beam-dev-tests/*.sqlite') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }
}
