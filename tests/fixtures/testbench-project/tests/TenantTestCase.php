<?php

/**
 * A FIXTURE, never autoloaded or executed — it exists to be READ by {@see SuiteHarness}.
 *
 * Reduced from `splicewire/tower`'s real `tests/TenantTestCase.php`, keeping only the two lines that
 * matter to the scan: the harness picks its database name out of an env var that is not
 * `DB_DATABASE`, and it builds a pgsql connection at runtime rather than using whatever
 * `database.default` happened to be when the console command ran.
 */

namespace Beam\Dev\Fixtures\TestbenchProject\Tests;

abstract class TenantTestCase
{
    protected function defineEnvironment($app): void
    {
        $database = env('TEST_DB_DATABASE', 'test_tower');

        $pgsql = [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'database' => $database,
        ];

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $pgsql);
    }
}
