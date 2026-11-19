<?php

namespace Splicewire\Beam\Dev\Tests;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;
use Splicewire\Beam\Dev\Databases\ServerConnection;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

class IsolatedArtisanTest extends TestCase
{
    public function test_native_runner_migrates_runs_literal_argument_tokens_and_reaps_its_database(): void
    {
        $this->app->make(Kernel::class)->registerCommand(new IsolatedArtisanProbe);
        $this->app['migrator']->path(__DIR__.'/fixtures/isolated-artisan/migrations');
        $original = config('database.connections.scratch');
        $this->assertArrayHasKey('splicewire:beam:dev:isolated-artisan', Artisan::all());

        $status = Artisan::call('splicewire:beam:dev:isolated-artisan', [
            '--connection' => 'scratch',
            'artisan' => 'beam-dev-fixture:inspect',
            'arguments' => ['literal $(not-a-shell) ; value', '--label=two words "quoted"', '--flag'],
        ]);
        $output = Artisan::output();
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('Verified reachable [', $output);
        $this->assertMatchesRegularExpression('/RUNNER_PROBE:(.+)/', $output);
        preg_match('/RUNNER_PROBE:(.+)/', $output, $match);
        $probe = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('literal $(not-a-shell) ; value', $probe['value']);
        $this->assertSame('two words "quoted"', $probe['label']);
        $this->assertTrue($probe['flag']);
        $this->assertSame('migration-ready', $probe['row']);
        $this->assertSame('scratch', $probe['connection']);
        $this->assertNotSame($original['database'], $probe['database']);
        $this->assertFileDoesNotExist($probe['database']);
        $this->assertSame($original, config('database.connections.scratch'));
        $this->assertSame('scratch', config('database.default'));
        $this->assertSame([], glob($this->scratchDir().'/test_*.sqlite'));
    }

    public function test_real_cli_separator_preserves_child_options_and_spaces(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->registerCommand(new IsolatedArtisanProbe);
        $this->app['migrator']->path(__DIR__.'/fixtures/isolated-artisan/migrations');
        $output = new BufferedOutput;
        $status = $kernel->handle(new ArgvInput([
            'artisan', 'splicewire:beam:dev:isolated-artisan', '--connection=scratch', '--',
            'beam-dev-fixture:inspect', 'literal $(not a shell)', '--label=two words', '--flag',
        ]), $output);

        $text = $output->fetch();
        $this->assertSame(0, $status, $text);
        preg_match('/RUNNER_PROBE:(.+)/', $text, $match);
        $this->assertNotEmpty($match);
        $probe = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('literal $(not a shell)', $probe['value']);
        $this->assertSame('two words', $probe['label']);
        $this->assertTrue($probe['flag']);
        $this->assertSame('migration-ready', $probe['row']);
        $this->assertFileDoesNotExist($probe['database']);
        $this->assertSame([], glob($this->scratchDir().'/test_*.sqlite'));
    }

    public function test_nonzero_command_status_and_output_survive_and_connections_are_restored_and_purged(): void
    {
        config()->set('database.connections.other', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::setDefaultConnection('other');
        $before = config('database.connections');
        $scratchHandle = DB::connection('scratch');
        $otherHandle = DB::connection('other');
        [$status, $output] = $this->outcome('nonzero');

        $this->assertSame(23, $status, $output);
        $this->assertStringContainsString('CHILD_OUTPUT', $output);
        $this->assertSame($before, config('database.connections'));
        $this->assertSame('other', DB::getDefaultConnection());
        $this->assertSame([], DB::getConnections());
        $this->assertNotSame($scratchHandle, DB::connection('scratch'));
        $this->assertNotSame($otherHandle, DB::connection('other'));
        $this->assertSame([], glob($this->scratchDir().'/test_*.sqlite'));
    }

    public function test_throwing_command_is_reaped_and_original_alias_and_default_are_restored(): void
    {
        $before = config('database.connections');
        [$status, $output] = $this->outcome('throw');

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('fixture command failed', $output);
        $this->assertStringContainsString('Reaped [', $output);
        $this->assertSame($before, config('database.connections'));
        $this->assertSame('scratch', DB::getDefaultConnection());
        $this->assertSame([], DB::getConnections());
        $this->assertSame([], glob($this->scratchDir().'/test_*.sqlite'));
    }

    public function test_each_invocation_uses_a_new_database(): void
    {
        [$first, $one] = $this->outcome('success');
        [$second, $two] = $this->outcome('success');
        preg_match('/Allocation issued \[([^\]]+)\]/', $one, $a);
        preg_match('/Allocation issued \[([^\]]+)\]/', $two, $b);

        $this->assertSame(0, $first, $one);
        $this->assertSame(0, $second, $two);
        $this->assertNotEmpty($a);
        $this->assertNotEmpty($b);
        $this->assertNotSame($a[1], $b[1]);
        $this->assertSame([], glob($this->scratchDir().'/test_*.sqlite'));
    }

    public function test_collision_is_not_adopted_overwritten_or_dropped(): void
    {
        $databases = new class($this->app->make(ServerConnection::class), config()) extends ScratchDatabases
        {
            public string $collision;

            public function createExclusive(string $name, string $connection): void
            {
                $this->collision = $this->targetValue($name, $connection);
                file_put_contents($this->collision, 'belongs to another run');
                parent::createExclusive($name, $connection);
            }
        };
        $this->app->instance(ScratchDatabases::class, $databases);
        [$status, $output] = $this->outcome('success');

        $this->assertSame(1, $status, $output);
        $this->assertSame('belongs to another run', file_get_contents($databases->collision));
        $this->assertStringNotContainsString('CHILD_OUTPUT', $output);
        $this->assertStringNotContainsString('Reaped [', $output);
    }

    public function test_unreachable_new_database_is_reaped_without_running_the_command(): void
    {
        $this->app->instance(ScratchDatabases::class, new class($this->app->make(ServerConnection::class), config()) extends ScratchDatabases
        {
            public function assertReachable(string $name, string $connection): void
            {
                throw new RuntimeException('fixture reachability failed');
            }
        });
        [$status, $output] = $this->outcome('success');

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('fixture reachability failed', $output);
        $this->assertStringContainsString('pending reachability verification', $output);
        $this->assertStringNotContainsString('Verified reachable [', $output);
        $this->assertStringNotContainsString('CHILD_OUTPUT', $output);
        $this->assertSame([], glob($this->scratchDir().'/test_*.sqlite'));
    }

    public function test_explicit_sqlite_init_runs_before_migrations_and_the_command(): void
    {
        $this->app->make(Kernel::class)->registerCommand(new IsolatedArtisanProbe);
        $this->app['migrator']->path(__DIR__.'/fixtures/isolated-artisan/migrations');
        $this->app['migrator']->path(__DIR__.'/fixtures/isolated-artisan/init-migrations');
        $sql = $this->scratchDir().'/init.sql';
        file_put_contents($sql, "create table init_probe (value text); insert into init_probe values ('init-before-migration');");

        $status = Artisan::call('splicewire:beam:dev:isolated-artisan', [
            '--connection' => 'scratch', '--init' => [$sql],
            'artisan' => 'beam-dev-fixture:inspect', 'arguments' => ['literal'],
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('init-before-migration', $output);
        $this->assertStringContainsString('Provisioning SQL completed.', $output);
        $this->assertSame([], glob($this->scratchDir().'/test_*.sqlite'));
    }

    public function test_invalid_or_missing_explicit_init_is_fatal_and_reaped_before_command(): void
    {
        $sql = $this->scratchDir().'/invalid.sql';
        file_put_contents($sql, 'not valid SQL');

        foreach ([$sql, $this->scratchDir().'/absent.sql'] as $file) {
            [$status, $output] = $this->outcome('success', ['--init' => [$file]]);
            $this->assertSame(1, $status, $output);
            $this->assertStringNotContainsString('CHILD_OUTPUT', $output);
            $this->assertStringNotContainsString('Provisioning SQL completed.', $output);
            $this->assertStringContainsString('Reaped [', $output);
            $this->assertSame([], glob($this->scratchDir().'/test_*.sqlite'));
        }
    }

    public function test_migration_failure_is_fatal_and_reaped_before_command(): void
    {
        $this->app['migrator']->path(__DIR__.'/fixtures/isolated-artisan/failing-migrations');
        [$status, $output] = $this->outcome('success');

        $this->assertSame(1, $status, $output);
        $this->assertStringNotContainsString('CHILD_OUTPUT', $output);
        $this->assertStringContainsString('Reaped [', $output);
        $this->assertSame([], glob($this->scratchDir().'/test_*.sqlite'));
    }

    public function test_teardown_refusal_is_nonzero_and_names_the_unresolved_database(): void
    {
        $this->app->instance(ScratchDatabases::class, new class($this->app->make(ServerConnection::class), config()) extends ScratchDatabases
        {
            public function activeConnections(string $name, string $connection): int
            {
                return 1;
            }
        });
        $before = config('database.connections');
        [$status, $output] = $this->outcome('success');

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('CHILD_OUTPUT', $output);
        $this->assertStringContainsString('UNRESOLVED [test_', $output);
        $this->assertStringContainsString('active connection', $output);
        $this->assertStringNotContainsString('Reaped [', $output);
        $this->assertCount(1, glob($this->scratchDir().'/test_*.sqlite'));
        $this->assertSame($before, config('database.connections'));
        $this->assertSame([], DB::getConnections());
    }

    public function test_production_transition_during_the_child_cannot_bypass_drop_guard(): void
    {
        [$status, $output] = $this->outcome('production');

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('CHILD_OUTPUT', $output);
        $this->assertStringContainsString('UNRESOLVED [test_', $output);
        $this->assertStringContainsString('production', $output);
        $this->assertStringNotContainsString('Reaped [', $output);
        $this->assertCount(1, glob($this->scratchDir().'/test_*.sqlite'));
    }

    public function test_teardown_exception_is_nonzero_and_restores_configuration(): void
    {
        $this->app->instance(ScratchDatabases::class, new class($this->app->make(ServerConnection::class), config()) extends ScratchDatabases
        {
            public function drop(string $name, string $connection): bool
            {
                throw new RuntimeException('fixture drop failed');
            }
        });
        $before = config('database.connections');
        [$status, $output] = $this->outcome('success');

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('UNRESOLVED [test_', $output);
        $this->assertStringContainsString('fixture drop failed', $output);
        $this->assertSame($before, config('database.connections'));
        $this->assertSame([], DB::getConnections());
        $this->assertCount(1, glob($this->scratchDir().'/test_*.sqlite'));
    }

    public function test_production_and_in_memory_and_ambiguous_routing_are_refused_before_allocation(): void
    {
        $this->app->make(Kernel::class)->registerCommand(new IsolatedArtisanOutcome);
        $original = config('database.connections.scratch');

        foreach ([['database' => ':memory:'], ['url' => 'sqlite://not-a-real-target'], ['read' => []], ['write' => []]] as $override) {
            config()->set('database.connections.scratch', array_merge($original, $override));
            [$status, $output] = $this->outcome('success');
            $this->assertSame(1, $status, $output);
            $this->assertStringNotContainsString('Allocation issued [', $output);
            $this->assertSame([], glob($this->scratchDir().'/test_*.sqlite'));
        }

        config()->set('database.connections.scratch', $original);
        $this->app['env'] = 'production';
        [$status, $output] = $this->outcome('success');
        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('production', $output);
        $this->assertStringNotContainsString('Allocation issued [', $output);
    }

    public function test_catchable_int_and_term_reap_and_restore_previous_signal_handlers(): void
    {
        if (! function_exists('pcntl_signal_get_handler') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are needed for the in-process signal fixture.');
        }

        foreach (['interrupt' => SIGINT, 'terminate' => SIGTERM] as $mode => $signal) {
            $handler = pcntl_signal_get_handler($signal);
            $async = pcntl_async_signals();
            [$status, $output] = $this->outcome($mode);
            $this->assertSame(128 + $signal, $status, $output);
            $this->assertStringContainsString('Reaped [', $output);
            $this->assertSame($handler, pcntl_signal_get_handler($signal));
            $this->assertSame($async, pcntl_async_signals());
            $this->assertSame('scratch', DB::getDefaultConnection());
            $this->assertSame([], glob($this->scratchDir().'/test_*.sqlite'));
        }
    }

    /** @return array{int, string} */
    private function outcome(string $mode, array $options = []): array
    {
        $this->app->make(Kernel::class)->registerCommand(new IsolatedArtisanOutcome);
        $this->app['migrator']->path(__DIR__.'/fixtures/isolated-artisan/migrations');
        $status = Artisan::call('splicewire:beam:dev:isolated-artisan', [
            '--connection' => 'scratch', 'artisan' => 'beam-dev-fixture:outcome',
            'arguments' => [$mode], ...$options,
        ]);

        return [$status, Artisan::output()];
    }

}

class IsolatedArtisanProbe extends Command
{
    protected $signature = 'beam-dev-fixture:inspect {value} {--label=} {--flag}';

    public function handle(): int
    {
        $this->line('RUNNER_PROBE:'.json_encode([
            'value' => $this->argument('value'),
            'label' => $this->option('label'),
            'flag' => $this->option('flag'),
            'row' => DB::table('runner_probe')->value('state'),
            'connection' => DB::getDefaultConnection(),
            'database' => DB::connection()->getDatabaseName(),
        ], JSON_THROW_ON_ERROR));

        return 0;
    }
}


class IsolatedArtisanOutcome extends Command
{
    protected $signature = 'beam-dev-fixture:outcome {mode}';

    public function handle(): int
    {
        // Both explicitly named and default handles must land on the migrated scratch database.
        if (DB::connection('scratch')->table('runner_probe')->value('state') !== 'migration-ready'
            || DB::table('runner_probe')->value('state') !== 'migration-ready') {
            throw new RuntimeException('fixture did not reach the migrated scratch database');
        }

        DB::connection('scratch::read')->select('select 1');
        DB::connection('scratch::write')->select('select 1');
        $this->line('CHILD_OUTPUT');

        return match ($this->argument('mode')) {
            'nonzero' => 23,
            'throw' => throw new RuntimeException('fixture command failed'),
            'production' => $this->switchToProduction(),
            'interrupt' => $this->signal(SIGINT),
            'terminate' => $this->signal(SIGTERM),
            default => 0,
        };
    }

    private function switchToProduction(): int
    {
        app()['env'] = 'production';

        return 0;
    }

    private function signal(int $signal): int
    {
        posix_kill(getmypid(), $signal);
        pcntl_signal_dispatch();

        throw new RuntimeException('fixture signal handler did not interrupt the command');
    }
}
