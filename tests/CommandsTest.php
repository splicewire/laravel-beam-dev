<?php

namespace Splicewire\Beam\Dev\Tests;

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
        return sys_get_temp_dir()."/beam-dev-tests/{$name}.sqlite";
    }

    public function test_it_creates_a_scratch_database_and_prints_the_env(): void
    {
        $output = $this->runCommand('splicewire:beam:dev:isolated-test-db', ['--slug' => 'abc123']);

        $this->assertStringContainsString('Created test_abc123', $output);
        // On SQLite the env value is the PATH to the file; a bare name would point at nothing.
        $this->assertStringContainsString('DB_DATABASE='.$this->pathFor('test_abc123'), $output);
        $this->assertStringContainsString('drop-db test_abc123', $output);

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
