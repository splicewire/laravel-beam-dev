<?php

namespace Splicewire\Beam\Dev\Databases;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Create, list, inspect and drop whole databases, engine-aware.
 *
 * Every method takes the name of a configured Laravel connection and works on the SERVER behind it
 * (see {@see ServerConnection}). SQLite is handled as file operations — it has no server, and a
 * scratch SQLite database is a file you create and delete.
 *
 * Nothing in this class decides whether a drop is ALLOWED — that is {@see DropGuard::refuse()}, and
 * no caller may reach `drop()` without asking it first. This class is the mechanism, deliberately
 * dumb: it will drop whatever it is told to, which is exactly why the policy is one auditable object
 * rather than conditions scattered through the engine branches.
 */
class ScratchDatabases
{
    public function __construct(
        private ServerConnection $server,
        private Repository $config,
    ) {}

    public function exists(string $name, string $connection): bool
    {
        if ($this->isSqlite($connection)) {
            return is_file($this->sqlitePath($name, $connection));
        }

        return $this->names($connection, $name) !== [];
    }

    /**
     * @return bool whether the database was created (false = it already existed)
     */
    public function create(string $name, string $connection): bool
    {
        $this->assertIdentifier($name);

        if ($this->exists($name, $connection)) {
            return false;
        }

        if ($this->isSqlite($connection)) {
            $path = $this->sqlitePath($name, $connection);
            @mkdir(dirname($path), 0755, true);
            touch($path);

            return true;
        }

        $this->server->for($connection)->statement("CREATE DATABASE {$this->quote($name, $connection)}");

        return true;
    }

    /**
     * @return bool whether the database was dropped (false = it did not exist)
     */
    public function drop(string $name, string $connection): bool
    {
        $this->assertIdentifier($name);

        if (! $this->exists($name, $connection)) {
            return false;
        }

        if ($this->isSqlite($connection)) {
            $path = $this->sqlitePath($name, $connection);

            // Reporting "it did not exist" for a file that exists and would not delete is the same
            // lie in miniature — the caller believes it was reaped and it is still there.
            if (! @unlink($path)) {
                throw new RuntimeException("Failed to delete SQLite database file [{$path}].");
            }

            return true;
        }

        $this->server->for($connection)->statement("DROP DATABASE IF EXISTS {$this->quote($name, $connection)}");

        return true;
    }

    /**
     * Database names on the server, optionally filtered by a SQL LIKE pattern (`test\_%`).
     *
     * @return list<string>
     */
    public function names(string $connection, ?string $like = null): array
    {
        if ($this->isSqlite($connection)) {
            return $this->sqliteNames($connection, $like);
        }

        $driver = $this->server->driverFor($connection);

        [$sql, $bindings] = match ($driver) {
            'pgsql' => [
                'select datname as name from pg_database where datistemplate = false'
                .($like === null ? '' : ' and datname like ?').' order by name',
                $like === null ? [] : [$like],
            ],
            'mysql', 'mariadb' => [
                'select schema_name as name from information_schema.schemata'
                .($like === null ? '' : ' where schema_name like ?').' order by name',
                $like === null ? [] : [$like],
            ],
            default => throw new InvalidArgumentException("Unsupported driver [{$driver}]."),
        };

        return array_map(
            static fn ($row) => (string) ((array) $row)['name'],
            $this->server->for($connection)->select($sql, $bindings),
        );
    }

    /**
     * How many sessions are currently connected to $name.
     *
     * This is the sibling-session guard. Two agents (or an agent and a human) running suites at the
     * same time each hold their own scratch database, and dropping one that another process is
     * mid-run on takes down that run with errors that look exactly like real test failures. A
     * non-zero count here is a refusal, not a warning.
     */
    public function activeConnections(string $name, string $connection): int
    {
        if ($this->isSqlite($connection)) {
            return 0; // No server, no sessions to count. A file in use is the OS's problem.
        }

        $driver = $this->server->driverFor($connection);

        [$sql, $bindings] = match ($driver) {
            'pgsql' => ['select count(*) as total from pg_stat_activity where datname = ? and pid <> pg_backend_pid()', [$name]],
            'mysql', 'mariadb' => ['select count(*) as total from information_schema.processlist where db = ? and id <> connection_id()', [$name]],
            default => throw new InvalidArgumentException("Unsupported driver [{$driver}]."),
        };

        $row = (array) $this->server->for($connection)->selectOne($sql, $bindings);

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Run one or more SQL files against $name — the provisioning hook.
     *
     * Extensions, roles and anything else a schema assumes exists BEFORE the first migration runs.
     * The failure this exists to prevent is quiet and expensive: a bare CREATE DATABASE looks fine,
     * then the first migration that needs `citext` or `vector` dies with an error about the
     * extension rather than about the missing provisioning step.
     *
     * @param  list<string>  $paths
     */
    public function runSqlFiles(string $name, string $connection, array $paths): void
    {
        if ($paths === []) {
            return;
        }

        if ($this->isSqlite($connection)) {
            throw new RuntimeException('SQLite scratch databases do not support --init SQL files.');
        }

        $target = $this->targetConnection($name, $connection);

        foreach ($paths as $path) {
            if (! is_file($path)) {
                throw new RuntimeException("Init SQL file [{$path}] does not exist.");
            }

            $target->unprepared((string) file_get_contents($path));
        }
    }

    /**
     * Prove the database is there before anything reports that it is.
     *
     * `create()` returning true says a statement was issued, not that a database resulted — and on
     * the SQLite path it can succeed against a directory nothing will ever read. This opens a
     * connection pointed AT the scratch database and runs a trivial query, which is the only claim
     * worth printing: a suite can reach it. Cheap, engine-agnostic, and it is what turns a silent
     * no-op into a loud failure.
     *
     * @throws RuntimeException when the database cannot be reached
     */
    public function assertReachable(string $name, string $connection): void
    {
        $derived = null;

        try {
            $target = $this->targetConnection($name, $connection);
            $derived = $target->getName();
            $target->select('select 1');
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Database [{$name}] on connection [{$connection}] is not reachable after creating it: "
                .$e->getMessage(),
                previous: $e,
            );
        } finally {
            // Don't leave a handle open on a database the caller is about to hand to a test run (or,
            // on the SQLite path, to drop).
            if ($derived !== null) {
                app('db')->purge($derived);
            }
        }
    }

    /**
     * The value an env var must carry for a run to land on $name.
     *
     * On a server engine that is the name. On SQLite it is a PATH, and emitting the bare name there
     * silently points the run at a file that does not exist — the same no-isolation failure one
     * layer down from the one this class guards against.
     */
    public function targetValue(string $name, string $connection): string
    {
        return $this->isSqlite($connection)
            ? $this->sqlitePath($name, $connection)
            : $name;
    }

    /**
     * A connection pointed AT the scratch database itself, for provisioning inside it.
     */
    public function targetConnection(string $name, string $connection): Connection
    {
        $config = (array) $this->config->get("database.connections.{$connection}");
        $derived = "beam-dev-target:{$connection}:{$name}";

        $this->config->set("database.connections.{$derived}", array_merge($config, [
            'database' => $this->targetValue($name, $connection),
        ]));

        app('db')->purge($derived);

        return app('db')->connection($derived);
    }

    public function isSqlite(string $connection): bool
    {
        return $this->server->driverFor($connection) === 'sqlite';
    }

    private function sqlitePath(string $name, string $connection): string
    {
        $configured = (string) ($this->config->get("database.connections.{$connection}.database") ?: database_path('database.sqlite'));
        $dir = is_dir($configured) ? $configured : dirname($configured);

        return rtrim($dir, '/')."/{$name}.sqlite";
    }

    /**
     * @return list<string>
     */
    private function sqliteNames(string $connection, ?string $like): array
    {
        $dir = dirname($this->sqlitePath('x', $connection));
        $names = [];

        foreach (glob(rtrim($dir, '/').'/*.sqlite') ?: [] as $file) {
            $name = basename($file, '.sqlite');

            // Translate the SQL LIKE the other drivers take into the equivalent match, so callers
            // don't need to know which engine they're on.
            if ($like === null || Str::is(str_replace(['\_', '%'], ['_', '*'], $like), $name)) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Database names are interpolated into DDL — they cannot be bound as parameters. So the name is
     * constrained to an identifier charset rather than escaped: anything that could carry a quote or
     * a statement terminator is rejected outright before it reaches a query.
     */
    private function assertIdentifier(string $name): void
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,63}$/', $name)) {
            throw new InvalidArgumentException(
                "Refusing to operate on database name [{$name}]: names must be 1-63 characters of "
                .'[A-Za-z0-9_] only. Database names cannot be parameter-bound, so anything outside that '
                .'charset is rejected rather than escaped.'
            );
        }
    }

    private function quote(string $name, string $connection): string
    {
        return match ($this->server->driverFor($connection)) {
            'mysql', 'mariadb' => "`{$name}`",
            default => "\"{$name}\"",
        };
    }
}
