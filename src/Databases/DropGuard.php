<?php

namespace Splicewire\Beam\Dev\Databases;

use Illuminate\Contracts\Foundation\Application;

/**
 * The one place that decides whether dropping a database is allowed.
 *
 * This package deletes whole databases, so the guard is deliberately a single object with a single
 * method rather than a set of `if`s spread through the command. Every refusal returns a reason a
 * human can act on, and the caller cannot drop anything without asking first.
 *
 * The four rules, in the order they are checked:
 *
 * 1. **Never in production.** Not gated behind `--force`, not behind a confirmation prompt. A flag
 *    that can delete a production database is a flag someone eventually passes by muscle memory in
 *    the wrong shell.
 * 2. **Never the connection's own database.** The database the app is configured to use is the one
 *    thing on the server that is certainly not scratch.
 * 3. **Only names matching the scratch prefix**, unless `--force`. The prefix is what separates "a
 *    throwaway this tool made" from "someone's database that happens to be on the same server".
 * 4. **Never one with live sessions.** A scratch database with another process connected is another
 *    agent's or human's test run, and pulling it mid-run produces failures that read exactly like
 *    real regressions. This one holds even under `--force`: force is for naming things outside the
 *    prefix, not for yanking a database out from under a running suite.
 */
class DropGuard
{
    public function __construct(
        private Application $app,
        private ScratchDatabases $databases,
    ) {}

    /**
     * @return string|null a refusal reason, or null when the drop may proceed
     */
    public function refuse(string $name, string $connection, string $prefix, bool $force): ?string
    {
        if ($this->app->environment('production')) {
            return 'refusing to drop any database while the application environment is [production]. '
                .'This is not overridable by --force.';
        }

        $configured = app(ServerConnection::class)->databaseFor($connection);

        if ($configured !== null && $name === $configured) {
            return "[{$name}] is the database connection [{$connection}] is configured to use. "
                .'Refusing to drop the connection out from under the application.';
        }

        if (! $force && ! str_starts_with($name, $prefix)) {
            return "[{$name}] does not start with the scratch prefix [{$prefix}]. "
                ."Rename it, change config('beam-dev.prefix'), or pass --force if you are certain.";
        }

        $active = $this->databases->activeConnections($name, $connection);

        if ($active > 0) {
            return "[{$name}] has {$active} active connection(s) — most likely another test run. "
                .'Refusing to drop it mid-run; --force does not override this. Wait for that run to '
                .'finish, or drop it explicitly from a session that owns it.';
        }

        return null;
    }
}
