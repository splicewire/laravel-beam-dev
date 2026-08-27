<?php

namespace Splicewire\Beam\Dev\Databases;

use Illuminate\Contracts\Config\Repository;

/**
 * The one place that decides whether a CONNECTION can host a scratch database at all.
 *
 * Sibling to {@see DropGuard}, and deliberately the same shape: it returns a reason a human can act
 * on rather than a bool, and no command may proceed without asking it first. DropGuard answers "may
 * this database be dropped"; this answers the earlier question, "is this connection something we can
 * isolate on" — a precondition of the connection, not a policy about any one database.
 *
 * It exists because of a measured defect (2026-08-27). Under a package testbench harness
 * `database.default` is an in-memory SQLite connection, and `isolated-test-db` had no driver guard:
 * it reported "Created test_k2uauqv7 on connection testing", touched a stray file in the process's
 * CURRENT WORKING DIRECTORY — `dirname(':memory:')` is `.` — and emitted an env assignment pointing
 * at no database at all. A session that followed the fleet instruction to use this command got a
 * success message and no isolation whatsoever, then attributed the resulting interference to its own
 * change.
 *
 * This refusal is deliberately FATAL rather than advisory. The estate's rule is that a check whose
 * answer depends on the host must be a finding, while a check the caller could have gotten right
 * without knowing the host may throw. "You pointed an isolation tool at an in-memory database" is
 * the latter: it is a fact about the invocation, and there is no host in which it is fine.
 */
class IsolationGuard
{
    public function __construct(
        private ServerConnection $server,
        private Repository $config,
    ) {}

    /**
     * @return string|null a refusal reason, or null when the connection can host a scratch database
     */
    public function refuse(string $connection): ?string
    {
        if (! $this->isInMemory($connection)) {
            return null;
        }

        return "Connection [{$connection}] is an in-memory SQLite database (`"
            .$this->databaseValue($connection).'`). There is nothing to isolate: it exists only for the '
            .'lifetime of one process, no other run can reach it, and a scratch database created '
            .'against it would be a file in the current working directory that nothing reads. '
            .$this->suggestion($connection);
    }

    /**
     * An in-memory SQLite connection, however it was spelled.
     *
     * `:memory:` is the common form; an empty database value resolves to the same thing, and a
     * shared-cache URI (`file::memory:?cache=shared`) is still memory-backed.
     */
    public function isInMemory(string $connection): bool
    {
        if ($this->server->driverFor($connection) !== 'sqlite') {
            return false;
        }

        $database = $this->databaseValue($connection);

        return $database === '' || $database === ':memory:' || str_contains($database, ':memory:');
    }

    private function databaseValue(string $connection): string
    {
        return (string) $this->config->get("database.connections.{$connection}.database");
    }

    /**
     * Name a connection that would actually work, rather than only saying no.
     *
     * A refusal with no next step is a refusal the caller routes around — which here means going back
     * to the hand-rolled `createdb` this package exists to replace.
     */
    private function suggestion(string $connection): string
    {
        // Ranked by driver, not by config order: a server engine is what an isolated run actually
        // wants, and a file-backed SQLite connection is the fallback rather than the headline.
        $rank = ['pgsql' => 0, 'mysql' => 1, 'mariadb' => 2, 'sqlite' => 3];
        $candidates = [];

        foreach (array_keys((array) $this->config->get('database.connections', [])) as $name) {
            $name = (string) $name;
            $driver = (string) $this->server->driverFor($name);

            if ($name === $connection || str_starts_with($name, 'beam-dev-')) {
                continue;
            }

            // Only drivers this package can actually manage databases on — suggesting one it would
            // refuse two lines later is worse than suggesting nothing.
            if (isset($rank[$driver]) && ! $this->isInMemory($name)) {
                $candidates[$name] = $rank[$driver];
            }
        }

        asort($candidates);
        $candidates = array_keys($candidates);

        if ($candidates === []) {
            return 'Configure a pgsql/mysql connection (or a file-backed SQLite one) and pass it as '
                .'--connection=<name>.';
        }

        return 'Pass a server-backed connection instead: --connection='.$candidates[0]
            .' (configured here: '.implode(', ', $candidates).').';
    }
}
