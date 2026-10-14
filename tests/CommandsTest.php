<?php

namespace Splicewire\Beam\Dev\Tests;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;

class CommandsTest extends TestCase
{
    /**
     * Run a command and return its real output.
     *
     * Deliberately not `$this->artisan(...)->expectsOutputToContain(...)`: that matcher hooks the
     * output object's doWrite and never sees multi-line styled output as one chunk, so it reports a
     * miss on text the command demonstrably prints. Asserting on the captured string tests what a
     * human actually sees.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function runCommand(string $command, array $arguments = []): string
    {
        Artisan::call($command, $arguments);

        return Artisan::output();
    }

    /**
     * What a SQLite scratch database of this name is called on disk — the value the emitted env has
     * to carry for a run to land on it.
     */
    private function pathFor(string $name): string
    {
        return $this->scratchDir()."/{$name}.sqlite";
    }

    public function test_witness_run_scrubs_dotenv_values_from_the_child_environment(): void
    {
        $fixture = sys_get_temp_dir().'/beam-dev-witness-env-'.getmypid().'-'.bin2hex(random_bytes(6));
        $log = $fixture.'/witness.log';
        $originalEnvironment = (array) getenv();
        $originalEnv = $_ENV;
        $originalServer = $_SERVER;
        $originalEnvironmentPath = $this->app->environmentPath();
        $originalEnvironmentFile = $this->app->environmentFile();
        $safeEnvironment = array_filter(
            $originalEnvironment,
            fn (string $name): bool => in_array($name, ['PATH', 'HOME', 'LANG', 'LANGUAGE'], true)
                || str_starts_with($name, 'LC_'),
            ARRAY_FILTER_USE_KEY,
        );
        $safeEnvironment['PATH'] = $safeEnvironment['PATH'] ?? '/usr/bin:/bin';
        $safeEnvironment['HOME'] = $safeEnvironment['HOME'] ?? sys_get_temp_dir();
        $safeEnvironment['LANG'] = 'C';
        $safeEnvironment['LC_ALL'] = 'C';

        mkdir($fixture, 0755, true);
        file_put_contents(
            $fixture.'/.env',
            "WITNESS_ENV_SENTINEL=sentinel\nWITNESS_ENV_OVERRIDE=dotenv\nAPP_ENV=local\n",
        );
        $this->replaceProcessEnvironment($safeEnvironment);
        $this->app->useEnvironmentPath($fixture)->loadEnvironmentFrom('.env');
        Dotenv::createUnsafeMutable($fixture)->load();
        putenv('TEST_DB_DATABASE=test_witness_isolated');
        putenv('XDEBUG_MODE=off');
        putenv('WITNESS_ENV_OVERRIDE=caller');

        try {
            $output = $this->runCommand('splicewire:beam:dev:witness-run', [
                'runner' => ['/usr/bin/env'],
                '--log' => $log,
            ]);

            $this->assertStringNotContainsString('WITNESS_ENV_SENTINEL=sentinel', $output);
            $this->assertStringContainsString('APP_ENV=testing', $output);
            $this->assertStringNotContainsString('APP_ENV=local', $output);
            $this->assertStringContainsString('PATH='.(string) getenv('PATH'), $output);
            $this->assertStringContainsString('HOME='.(string) getenv('HOME'), $output);
            $this->assertStringContainsString('LANG='.(string) getenv('LANG'), $output);
            $this->assertStringContainsString('LC_ALL='.(string) getenv('LC_ALL'), $output);
            $this->assertStringContainsString('TEST_DB_DATABASE=test_witness_isolated', $output);
            $this->assertStringContainsString('XDEBUG_MODE=off', $output);
            $this->assertStringContainsString('WITNESS_ENV_OVERRIDE=caller', $output);
            $this->assertStringNotContainsString('WITNESS_ENV_OVERRIDE=dotenv', $output);
        } finally {
            $this->replaceProcessEnvironment($originalEnvironment);
            $_ENV = $originalEnv;
            $_SERVER = $originalServer;
            $this->app->useEnvironmentPath($originalEnvironmentPath)->loadEnvironmentFrom($originalEnvironmentFile);
            @unlink($log);
            @unlink($fixture.'/.env');
            @rmdir($fixture);
        }
    }

    /** @param array<string, string> $environment */
    private function replaceProcessEnvironment(array $environment): void
    {
        foreach (array_keys((array) getenv()) as $name) {
            putenv($name);
        }

        foreach ($environment as $name => $value) {
            putenv($name.'='.$value);
        }
    }

    public function test_it_creates_a_scratch_database_and_prints_the_env(): void
    {
        $output = $this->runCommand('splicewire:beam:dev:isolated-test-db', ['--slug' => 'abc123']);

        $this->assertStringContainsString('Created test_abc123', $output);
        // On SQLite the env value is the PATH to the file; a bare name would point at nothing.
        $this->assertStringContainsString('DB_DATABASE='.$this->pathFor('test_abc123'), $output);
        $this->assertStringContainsString('drop-db test_abc123', $output);
        // A storage override belongs only to a separate seed/import rehearsal. Printing a bare assignment beside the
        // suite recipe led callers to export it into the suite, where nav.yml disappeared behind the empty scratch root.
        $this->assertStringContainsString(sys_get_temp_dir().'/test_abc123-storage', $output);
        $this->assertStringContainsString('Do not export BEAM_SCRATCH_STORAGE_ROOT into the suite environment.', $output);
        $this->assertStringNotContainsString('BEAM_SCRATCH_STORAGE_ROOT='.sys_get_temp_dir().'/test_abc123-storage', $output);

        $this->assertTrue($this->app->make(ScratchDatabases::class)->exists('test_abc123', 'scratch'));
    }

    /**
     * Re-running must be free. A provisioning step that fails the second time forces every caller to
     * guard it, which is how "just run this first" turns into a script nobody wants to touch.
     */
    public function test_creating_is_idempotent(): void
    {
        $this->artisan('splicewire:beam:dev:isolated-test-db', ['--slug' => 'abc123'])->assertSuccessful();

        $this->artisan('splicewire:beam:dev:isolated-test-db', ['--slug' => 'abc123'])
            ->expectsOutputToContain('Reusing existing')
            ->assertSuccessful();
    }

    /**
     * Reuse is the default (rig-hardening slice 05): with no slug, a seat gets ONE database per worktree, named
     * <seat>_<rig>_<worktree hash>, and the second run reuses it. A random default made every run a new database.
     */
    public function test_the_default_slug_is_seat_rig_and_worktree_so_a_rerun_reuses_its_database(): void
    {
        putenv('OPENRIG_SESSION_NAME=build-impl@launch');
        $expected = 'test_build_impl_launch_'.substr(hash('sha256', realpath((string) getcwd())), 0, 8);

        try {
            $this->assertStringContainsString('Created '.$expected, $this->runCommand('splicewire:beam:dev:isolated-test-db'));
            $this->assertStringContainsString('Reusing existing '.$expected, $this->runCommand('splicewire:beam:dev:isolated-test-db'));
        } finally {
            putenv('OPENRIG_SESSION_NAME');
        }
    }

    /**
     * review-r1 P4: the seat name before '@' alone collided across worktrees and across rigs.
     */
    public function test_two_worktrees_or_two_rigs_never_share_the_default_database(): void
    {
        $names = [];
        $here = getcwd();

        try {
            foreach ([['build-impl@launch', sys_get_temp_dir()], ['build-impl@launch', $here], ['build-impl@other', $here]] as [$seat, $dir]) {
                putenv("OPENRIG_SESSION_NAME={$seat}");
                chdir($dir);
                preg_match('/Created (test_\w+)/', $this->runCommand('splicewire:beam:dev:isolated-test-db'), $m);
                $names[] = $m[1] ?? null;
            }
        } finally {
            chdir($here);
            putenv('OPENRIG_SESSION_NAME');
        }

        $this->assertCount(3, array_unique(array_filter($names)), implode(' ', $names));
    }

    /**
     * The launch rig's test-full derives the same name (tests/test-full.test.mjs pins this vector).
     */
    public function test_the_default_name_matches_the_launch_rig_runner(): void
    {
        $here = getcwd();
        putenv('OPENRIG_SESSION_NAME=build-impl@launch');

        try {
            chdir('/');
            $this->assertStringContainsString('Created test_build_impl_launch_8a5edab2', $this->runCommand('splicewire:beam:dev:isolated-test-db'));
        } finally {
            chdir($here);
            putenv('OPENRIG_SESSION_NAME');
        }
    }

    public function test_outside_a_rig_the_default_slug_is_the_checkout_and_its_hash(): void
    {
        putenv('OPENRIG_SESSION_NAME');
        $cwd = realpath((string) getcwd());

        $this->assertStringContainsString('Created '.$this->expectedOutsideRig($cwd), $this->runCommand('splicewire:beam:dev:isolated-test-db'));
    }

    /**
     * PostgreSQL silently truncates identifiers to 63 bytes, so the command shortens the checkout part and keeps the
     * `_norig_<hash>` tail whole: the hash is what keeps two worktrees apart. A long worktree basename (the
     * `.wt-<seat>-<repo>-<slice>` convention easily passes 43 characters) must yield that truncated name.
     */
    public function test_a_long_checkout_basename_is_truncated_to_postgres_63_keeping_the_hash(): void
    {
        $here = getcwd();
        $dir = sys_get_temp_dir().'/'.str_repeat('a-very-long-checkout-basename-', 3).bin2hex(random_bytes(4));
        mkdir($dir);
        putenv('OPENRIG_SESSION_NAME');

        try {
            chdir($dir);
            $cwd = realpath($dir);
            $expected = $this->expectedOutsideRig($cwd);

            $this->assertSame(63, strlen($expected));
            $this->assertStringEndsWith('_norig_'.substr(hash('sha256', $cwd), 0, 8), $expected);
            $this->assertStringContainsString('Created '.$expected, $this->runCommand('splicewire:beam:dev:isolated-test-db'));
        } finally {
            chdir($here);
            rmdir($dir);
        }
    }

    /** The command's outside-a-rig name: `test_` + the cleaned checkout basename cut to fit 63 + `_norig_<hash8>`. */
    private function expectedOutsideRig(string $cwd): string
    {
        $tail = '_norig_'.substr(hash('sha256', $cwd), 0, 8);
        $checkout = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', basename($cwd)), '_'));

        return 'test_'.substr($checkout, 0, 63 - strlen('test_') - strlen($tail)).$tail;
    }

    public function test_it_says_keep_the_database_and_drop_in_a_batch_not_reap_per_run(): void
    {
        $output = $this->runCommand('splicewire:beam:dev:isolated-test-db', ['--slug' => 'kept']);

        $this->assertStringContainsString('Keep it: the next run with the same slug reuses it', $output);
        $this->assertStringContainsString('in a batch at the end of the working session', $output);
        $this->assertStringNotContainsString('Reap it when the run is done', $output);
    }

    /**
     * Every env var listed in config must be emitted, not just the first. Overriding one while a
     * second connection still reads the shared database is the most common way isolation silently
     * does nothing.
     */
    public function test_it_emits_every_configured_env_var(): void
    {
        config()->set('beam.dev.env', ['DB_DATABASE', 'TEST_DB_DATABASE']);

        $output = $this->runCommand('splicewire:beam:dev:isolated-test-db', ['--slug' => 'pair']);

        $this->assertStringContainsString('DB_DATABASE='.$this->pathFor('test_pair'), $output);
        $this->assertStringContainsString('TEST_DB_DATABASE='.$this->pathFor('test_pair'), $output);
    }

    public function test_extra_vars_are_emitted_alongside_the_configured_ones(): void
    {
        $output = $this->runCommand('splicewire:beam:dev:isolated-test-db', [
            '--slug' => 'extra',
            '--var' => ['SECONDARY_DB'],
        ]);

        $this->assertStringContainsString('DB_DATABASE='.$this->pathFor('test_extra'), $output);
        $this->assertStringContainsString('SECONDARY_DB='.$this->pathFor('test_extra'), $output);
    }

    public function test_it_drops_a_named_database(): void
    {
        $databases = $this->app->make(ScratchDatabases::class);
        $databases->create('test_gone', 'scratch');

        $this->artisan('splicewire:beam:dev:drop-db', ['names' => ['test_gone']])->assertSuccessful();

        $this->assertFalse($databases->exists('test_gone', 'scratch'));
    }

    public function test_it_drops_many_at_once(): void
    {
        $databases = $this->app->make(ScratchDatabases::class);
        $databases->create('test_one', 'scratch');
        $databases->create('test_two', 'scratch');

        $this->artisan('splicewire:beam:dev:drop-db', ['names' => ['test_one', 'test_two']])->assertSuccessful();

        $this->assertFalse($databases->exists('test_one', 'scratch'));
        $this->assertFalse($databases->exists('test_two', 'scratch'));
    }

    /**
     * Parallel workers make databases nobody named. Reaping the parent without them is how strays
     * accumulate — and by the time they turn up in a `--all` sweep, nobody can attribute them.
     */
    public function test_dropping_a_database_also_drops_its_parallel_worker_databases(): void
    {
        $databases = $this->app->make(ScratchDatabases::class);
        $databases->create('test_session', 'scratch');
        $databases->create('test_session_test_1', 'scratch');
        $databases->create('test_session_test_2', 'scratch');
        $databases->create('test_sessionx', 'scratch');

        $this->artisan('splicewire:beam:dev:drop-db', ['names' => ['test_session']])->assertSuccessful();

        $this->assertFalse($databases->exists('test_session', 'scratch'));
        $this->assertFalse($databases->exists('test_session_test_1', 'scratch'));
        $this->assertFalse($databases->exists('test_session_test_2', 'scratch'));

        // The underscores in the derived pattern are escaped, so a neighbouring name that merely
        // shares the prefix is not swept in as a "worker".
        $this->assertTrue($databases->exists('test_sessionx', 'scratch'));
    }

    public function test_keep_workers_leaves_the_worker_databases_alone(): void
    {
        $databases = $this->app->make(ScratchDatabases::class);
        $databases->create('test_keep', 'scratch');
        $databases->create('test_keep_test_1', 'scratch');

        $this->artisan('splicewire:beam:dev:drop-db', ['names' => ['test_keep'], '--keep-workers' => true])
            ->assertSuccessful();

        $this->assertFalse($databases->exists('test_keep', 'scratch'));
        $this->assertTrue($databases->exists('test_keep_test_1', 'scratch'));
    }

    public function test_all_sweeps_only_the_prefix(): void
    {
        $databases = $this->app->make(ScratchDatabases::class);
        $databases->create('test_sweep_me', 'scratch');
        $databases->create('keepme', 'scratch');

        $this->artisan('splicewire:beam:dev:drop-db', ['--all' => true])->assertSuccessful();

        $this->assertFalse($databases->exists('test_sweep_me', 'scratch'));
        $this->assertTrue($databases->exists('keepme', 'scratch'));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $databases = $this->app->make(ScratchDatabases::class);
        $databases->create('test_dry', 'scratch');

        $this->artisan('splicewire:beam:dev:drop-db', ['names' => ['test_dry'], '--dry-run' => true])
            ->expectsOutputToContain('Would drop')
            ->assertSuccessful();

        $this->assertTrue($databases->exists('test_dry', 'scratch'));
    }

    /**
     * Naming nothing must be an error, not a sweep. "Drop everything" is never the safe default for a
     * command whose whole job is deletion.
     */
    public function test_it_refuses_to_guess_what_to_drop(): void
    {
        $this->artisan('splicewire:beam:dev:drop-db')
            ->expectsOutputToContain('Refusing to guess')
            ->assertFailed();
    }

    public function test_it_rejects_a_name_that_is_not_an_identifier(): void
    {
        $this->expectExceptionMessage('names must be 1-63 characters');

        $this->app->make(ScratchDatabases::class)->create('test_a"; drop database x; --', 'scratch');
    }
}
