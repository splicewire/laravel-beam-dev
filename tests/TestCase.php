<?php

namespace Splicewire\Beam\Dev\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Splicewire\Beam\Dev\BeamDevServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * This run's own scratch directory, or null until something asks for it.
     *
     * Keyed per test instance rather than fixed, because the code under test SWEEPS this directory:
     * `--all` drops every prefixed database it finds there, and `tearDown` used to unlink every
     * `*.sqlite`. On a shared path that reaches a concurrent session's databases mid-run — the exact
     * collision this package exists to prevent, in this package's own harness.
     */
    private ?string $scratchDir = null;

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BeamDevServiceProvider::class];
    }

    /**
     * Where this test's SQLite files live. The pid keys it to the process (including a parallel
     * worker, which gets its own), and the random suffix keys it to the test instance, so nothing
     * here is reachable by a neighbouring run or by an earlier one that died without reaping.
     */
    protected function scratchDir(): string
    {
        return $this->scratchDir ??= sys_get_temp_dir()
            .'/beam-dev-tests/'.getmypid().'-'.bin2hex(random_bytes(6));
    }

    /**
     * SQLite on a temp directory is the default harness: it exercises the real create/list/drop paths
     * without needing a database server in CI. The engine-specific SQL for pgsql/mysql is asserted
     * separately, by inspection rather than execution.
     */
    protected function defineEnvironment($app): void
    {
        $dir = $this->scratchDir();
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

    /**
     * Reap only this run's directory. A glob over the shared parent would delete files a concurrent
     * session is still using; `rmdir` on the parent is left to fail harmlessly while any other run
     * still has a directory in there.
     */
    protected function tearDown(): void
    {
        if ($this->scratchDir !== null) {
            foreach (glob($this->scratchDir.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->scratchDir);
            @rmdir(dirname($this->scratchDir));

            $this->scratchDir = null;
        }

        parent::tearDown();
    }
}
