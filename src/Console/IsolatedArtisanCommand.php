<?php

namespace Splicewire\Beam\Dev\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Splicewire\Beam\Dev\Databases\DropGuard;
use Splicewire\Beam\Dev\Databases\IsolationGuard;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;
use Splicewire\Beam\Dev\Databases\ServerConnection;
use Splicewire\Beam\Dev\Databases\SuiteHarness;
use Symfony\Component\Console\Input\ArgvInput;
use Throwable;

/** Own allocation through teardown; the caller supplies tokens, never a child DB environment. */
class IsolatedArtisanCommand extends Command
{
    protected $signature = 'splicewire:beam:dev:isolated-artisan
        {artisan : Artisan command to run after migrations}
        {arguments?* : Literal command arguments (put -- before the command)}
        {--connection= : Configured connection alias to isolate (default: app default)}
        {--init=* : Strict provisioning SQL files; otherwise use beam.dev.init}';

    protected $description = 'Run Artisan on a fresh migrated scratch database and reap it behind native guards';

    public function handle(
        ScratchDatabases $databases,
        ServerConnection $server,
        IsolationGuard $isolation,
        DropGuard $guard,
        SuiteHarness $harness,
        DatabaseManager $db,
    ): int {
        $connection = (string) ($this->option('connection') ?: $db->getDefaultConnection());
        $prefix = (string) config('beam.dev.prefix', 'test_');
        $name = $prefix.bin2hex(random_bytes(12));
        $owned = false;
        $allocationAttempted = false;
        $status = self::FAILURE;
        $default = $db->getDefaultConnection();
        $connections = (array) config('database.connections');
        $original = $connections[$connection] ?? null;
        $derived = "beam-dev-target:{$connection}:{$name}";
        $maintenance = "beam-dev-server:{$connection}";
        $signals = [];
        $async = null;
        $interrupted = null;
        $finishing = false;

        try {
            if (app()->environment('production')) {
                throw new RuntimeException('Isolated Artisan refuses the production environment.');
            }

            if (! is_string($default) || $default === '') {
                throw new RuntimeException('A configured default connection is required for restoration.');
            }

            if (! is_array($original)) {
                throw new RuntimeException("No database connection [{$connection}] is configured.");
            }

            // A URL or read/write branch can override database after a shallow merge. Never pretend
            // to isolate a configuration whose routing this runner cannot unambiguously retarget.
            if ((array_key_exists('url', $original) && $original['url'] !== null && $original['url'] !== '')
                || array_key_exists('read', $original) || array_key_exists('write', $original)) {
                throw new RuntimeException("Connection [{$connection}] uses URL/read-write routing; isolation is not supported.");
            }

            if ($reason = $isolation->refuse($connection)) {
                throw new RuntimeException($reason);
            }

            $target = $this->getApplication()->find((string) $this->argument('artisan'));

            if ($target->getName() === $this->getName()) {
                throw new RuntimeException('Recursive isolated-artisan execution is not supported.');
            }

            $files = $this->initFiles($harness, $server->driverFor($connection));
            $allocationAttempted = true;
            $databases->createExclusive($name, $connection);
            $owned = true;
            $this->line("Allocation issued [{$name}] on connection [{$connection}] (pending reachability verification).");

            // Catchable INT/TERM unwind through finally. Uncatchable termination, exit(), and a
            // termination during the allocation syscall remain explicit recovery limits.
            if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal_get_handler')) {
                $async = pcntl_async_signals(true);

                foreach ([SIGINT, SIGTERM] as $signal) {
                    $signals[$signal] = pcntl_signal_get_handler($signal);
                    $registered = pcntl_signal($signal, function (int $received) use (&$interrupted, &$finishing): void {
                        if (! $finishing) {
                            $interrupted = 128 + $received;

                            throw new RuntimeException("Isolated Artisan interrupted by signal [{$received}].");
                        }
                    });

                    if (! $registered) {
                        throw new RuntimeException('Could not register an interruption handler.');
                    }
                }
            }

            $databases->assertReachable($name, $connection);
            $this->line("Verified reachable [{$name}] on connection [{$connection}].");
            $databases->runSqlFiles($name, $connection, $files);
            $this->line($files === [] ? 'No provisioning SQL declared for this driver.' : 'Provisioning SQL completed.');

            $db->purge($connection);
            config()->set("database.connections.{$connection}", array_merge($original, [
                'database' => $databases->targetValue($name, $connection),
            ]));
            $db->setDefaultConnection($connection);

            if ($this->call('migrate', ['--database' => $connection, '--force' => true, '--no-interaction' => true]) !== 0) {
                throw new RuntimeException('Scratch migrations failed; requested command was not run.');
            }

            $input = new ArgvInput(['artisan', $target->getName(), ...array_map(strval(...), (array) $this->argument('arguments'))]);
            $input->setInteractive($this->input->isInteractive());
            $status = $target->run($input, $this->output);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());
            $status = $interrupted ?? self::FAILURE;
        } finally {
            $finishing = true;

            if ($owned) {
                try {
                    // DropGuard must see the original alias, never the retargeted scratch database.
                    $this->restoreConnections($db, $connections, $connection, $default, [$derived, $maintenance]);

                    if ($reason = $guard->refuse($name, $connection, $prefix, false)) {
                        throw new RuntimeException($reason);
                    }

                    if (! $databases->drop($name, $connection)) {
                        throw new RuntimeException('The owned scratch database disappeared before teardown.');
                    }

                    $this->line("Reaped [{$name}].");
                } catch (Throwable $e) {
                    $this->components->error("UNRESOLVED [{$name}] on [{$connection}]: {$e->getMessage()}");
                    $status = self::FAILURE;
                }
            }

            // Failed CREATE can open maintenance without acquiring database ownership. Guard/drop
            // can also reopen it. Restore these handles on both paths, without dropping a collision.
            if ($allocationAttempted) {
                try {
                    $this->restoreConnections($db, $connections, $connection, $default, [$derived, $maintenance]);
                } catch (Throwable $e) {
                    $this->components->error("UNRESOLVED connection restoration for [{$name}]: {$e->getMessage()}");
                    $status = self::FAILURE;
                }
            }

            foreach ($signals as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }

            if ($async !== null) {
                pcntl_async_signals($async);
            }
        }

        return $status;
    }

    /**
     * Purge selected/default and derived read/write handles; restore configuration even if purge fails.
     *
     * @param  array<string, mixed>  $connections
     * @param  list<string>  $derived
     */
    private function restoreConnections(DatabaseManager $db, array $connections, string $connection, string $default, array $derived): void
    {
        $aliases = array_unique([$connection, $default, $db->getDefaultConnection(), ...$derived]);

        try {
            foreach (array_keys($db->getConnections()) as $alias) {
                foreach ($aliases as $base) {
                    if ($alias === $base || str_starts_with($alias, $base.'::')) {
                        $db->purge($alias);
                        break;
                    }
                }
            }
        } finally {
            $current = (array) config('database.connections');

            foreach (array_unique([$connection, $default, ...$derived]) as $alias) {
                if (array_key_exists($alias, $connections)) {
                    $current[$alias] = $connections[$alias];
                } else {
                    unset($current[$alias]);
                }
            }

            config()->set('database.connections', $current);
            $db->setDefaultConnection($default);
        }
    }

    /**
     * @return list<string>
     */
    private function initFiles(SuiteHarness $harness, ?string $driver): array
    {
        $declared = $this->option('init') ?: config('beam.dev.init');
        $files = $declared === null
            ? ($driver === 'pgsql' ? [dirname(__DIR__, 2).'/database/init/extensions.sql'] : [])
            : (array) $declared;

        return array_values(array_map(
            static fn ($file) => $harness->resolveProjectPath((string) $file, base_path()),
            $files,
        ));
    }
}
