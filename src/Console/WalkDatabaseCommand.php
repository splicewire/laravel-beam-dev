<?php

namespace Splicewire\Beam\Dev\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Splicewire\Beam\Dev\Databases\DropGuard;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;
use Splicewire\Beam\Dev\Databases\ServerConnection;
use Splicewire\Beam\Dev\Databases\WalkDatabaseTarget;
use Throwable;

class WalkDatabaseCommand extends Command
{
    protected $signature = 'splicewire:beam:dev:walk-db
        {action : create or drop}
        {--room= : Owned private walk room}
        {--socket= : PostgreSQL Unix socket directory inside the room}
        {--port= : Private server port}
        {--user= : Private PostgreSQL role}
        {--init=* : Required project SQL files for create (strict)}
        {--write-env= : Existing scratch app .env inside the room to update on create}
        {--name= : Exact name returned by create, required for drop}';

    protected $description = 'Allocate and retire a walk-serving database on a private room PostgreSQL cluster';

    public function handle(ScratchDatabases $databases, DropGuard $guard, ServerConnection $server, DatabaseManager $db): int
    {
        if (app()->environment('production')) {
            $this->components->error('Walk databases are refused in production.');

            return self::FAILURE;
        }

        $prefix = (string) config('beam.dev.prefix', 'test_').'walk_';
        $action = $this->argument('action');
        $files = $this->option('init');
        $name = $this->option('name');
        $env = $this->option('write-env');
        try {
            if ($env === null && $this->input->hasParameterOption('--write-env')) {
                throw new RuntimeException('--write-env requires the scratch app .env path.');
            }
            if (! preg_match('/^[a-z_][a-z0-9_]{0,38}$/D', $prefix)) {
                throw new RuntimeException('The configured scratch prefix cannot form a walk identifier.');
            }

            if ($action === 'create' && $name === null && $files !== []) {
                $name = $prefix.bin2hex(random_bytes(12));
            } elseif ($action !== 'drop' || ! is_string($name)
                || ! preg_match('/^'.preg_quote($prefix, '/').'[a-f0-9]{24}$/D', $name)
                || $files !== [] || $env !== null) {
                throw new RuntimeException('Create requires explicit --init and generates its own name; drop requires the returned --name only.');
            }

            foreach ($files as $file) {
                if (! is_file($file) || ! is_readable($file)) {
                    throw new RuntimeException('An explicit project init file is missing or unreadable.');
                }
            }

            $target = new WalkDatabaseTarget($this->option('room'), $this->option('socket'), $this->option('port'), $this->option('user'));
            $envPath = $env === null ? null : $target->envPath($env);
        } catch (RuntimeException $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }

        $alias = 'beam-dev-walk';
        $maintenance = "beam-dev-server:{$alias}";
        $provisioning = "beam-dev-target:{$alias}:{$name}";
        $original = config('database.connections');
        $owned = false;
        $stage = 'private PostgreSQL verification';
        try {
            config()->set("database.connections.{$alias}", $target->connection());
            $target->verify($server->for($alias));

            if ($action === 'drop') {
                $stage = 'guarded retirement';

                return $this->retire($databases, $guard, $name, $alias, $prefix);
            }

            $stage = 'exclusive allocation';
            $databases->createExclusive($name, $alias);
            $owned = true;
            $stage = 'reachable walk database verification';
            $databases->assertReachable($name, $alias);
            $stage = 'strict project init';
            $databases->runSqlFiles($name, $alias, $files);
            $db->purge($provisioning);

            if ($envPath !== null) {
                $stage = 'scratch .env write';
                $target->writeEnv($envPath, $name);
                $this->line('Wrote database keys to the scoped scratch .env.');
            }

            $this->line("Walk database ready [{$name}] (private PostgreSQL socket, strict project init).");

            return self::SUCCESS;
        } catch (Throwable) {
            // Laravel/PDO errors can contain connection strings or literal project SQL. Keep output secret-safe.
            $this->components->error("Walk database {$stage} failed [{$name}].");
            if ($owned) {
                $db->purge($provisioning);
                try {
                    if ($this->retire($databases, $guard, $name, $alias, $prefix) !== self::SUCCESS) {
                        $this->components->error("UNRESOLVED [{$name}].");
                    }
                } catch (Throwable) {
                    $this->components->error("UNRESOLVED [{$name}].");
                }
            }

            return self::FAILURE;
        } finally {
            $db->purge($provisioning);
            $db->purge($maintenance);
            $db->purge($alias);
            config()->set('database.connections', $original);
        }
    }

    private function retire(ScratchDatabases $databases, DropGuard $guard, string $name, string $alias, string $prefix): int
    {
        // The temporary alias always names the maintenance database, even when the app's .env
        // points to this scratch name. DropGuard still sees live sessions on the actual target.
        if ($reason = $guard->refuse($name, $alias, $prefix, false)) {
            $this->components->error($reason);

            return self::FAILURE;
        }

        $dropped = $databases->drop($name, $alias);
        if (in_array($name, $databases->names($alias, $name), true)) {
            $this->components->error("Database [{$name}] is still present after guarded retirement.");

            return self::FAILURE;
        }

        $this->line($dropped ? "Retired [{$name}]." : "Already absent [{$name}].");

        return self::SUCCESS;
    }
}
