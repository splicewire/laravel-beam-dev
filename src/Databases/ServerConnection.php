<?php

namespace Splicewire\Beam\Dev\Databases;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * A connection to the SERVER rather than to one database on it.
 *
 * `CREATE DATABASE` / `DROP DATABASE` cannot run through a connection that is pointed at the
 * database being created or dropped — Postgres refuses outright ("cannot drop the currently open
 * database"), and even where an engine allows it the session is left pointing at nothing. So every
 * operation here borrows the target connection's HOST, PORT and CREDENTIALS and swaps the database
 * out for one that always exists.
 *
 * Borrowing credentials rather than asking for them is the whole point of `--connection`: the DSN a
 * project already configured is the one that is known to work, so an isolated test database is
 * provisioned with exactly the access the app itself has. There is nothing new to configure and
 * nothing to keep in sync.
 */
class ServerConnection
{
    public function __construct(
        private DatabaseManager $db,
        private Repository $config,
    ) {}

    /**
     * Resolve a server-level connection derived from $connection.
     */
    public function for(string $connection): Connection
    {
        $config = $this->configFor($connection);
        $driver = $config['driver'] ?? null;

        $name = "beam-dev-server:{$connection}";

        $this->config->set("database.connections.{$name}", array_merge($config, [
            'database' => $this->serverDatabaseFor($driver, $config),
        ]));

        // Purge first: the derived connection is cached by name, and a caller that changed the
        // underlying credentials mid-process (a test switching connections, say) would otherwise keep
        // the stale handle.
        $this->db->purge($name);

        return $this->db->connection($name);
    }

    /**
     * The LOGICAL database name the target connection points at — never a legal drop target.
     *
     * Normalised so it can be compared against a scratch name directly. On a server engine the
     * configured value already is the name; on SQLite it is a filesystem path, and comparing a bare
     * name against a path would silently never match — which would let the guard wave through a drop
     * of the app's own database, the exact case it exists to stop.
     */
    public function databaseFor(string $connection): ?string
    {
        $config = $this->configFor($connection);
        $database = $config['database'] ?? null;

        if (! is_string($database)) {
            return null;
        }

        return ($config['driver'] ?? null) === 'sqlite'
            ? basename($database, '.sqlite')
            : $database;
    }

    public function driverFor(string $connection): ?string
    {
        return $this->configFor($connection)['driver'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function configFor(string $connection): array
    {
        $config = $this->config->get("database.connections.{$connection}");

        if (! is_array($config)) {
            throw new InvalidArgumentException(
                "No database connection [{$connection}] is configured. Pass --connection with one of: "
                .implode(', ', array_keys((array) $this->config->get('database.connections', [])))
                .'.'
            );
        }

        return $config;
    }

    /**
     * A database that is guaranteed to exist on the server, so the maintenance session has somewhere
     * to live while it operates on another.
     *
     * @param  array<string, mixed>  $config
     */
    private function serverDatabaseFor(?string $driver, array $config): ?string
    {
        return match ($driver) {
            // Present on every Postgres cluster by default. `template1` would also work but is the
            // template every CREATE DATABASE copies, and connecting to it blocks that.
            'pgsql' => 'postgres',
            // MySQL/MariaDB accept a connection with no database selected.
            'mysql', 'mariadb' => null,
            // SQLite has no server; the file-based driver never reaches here (see ScratchDatabases).
            default => throw new InvalidArgumentException(
                "beam-dev cannot manage databases on driver [{$driver}]. Supported: pgsql, mysql, mariadb, sqlite."
            ),
        };
    }
}
