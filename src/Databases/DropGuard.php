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
 *    thing on the server that is certainly not scratch. One narrow exception, `--own-scratch=<prefix>`
 *    ({@see refuseOwnScratch()}): a host pointed AT a scratch database (a per-worktree `test_space_*`
 *    one whose `.env` names it) may drop it from its own app, on pgsql only, when the name starts with
 *    a caller prefix that extends both `test_` and the scratch prefix. Rules 1 and 4 still apply.
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
     * @param  string|null  $ownScratch  the `--own-scratch` prefix, when the caller passed one
     * @param  bool  $named  whether $name was named explicitly by the caller (not found by a sweep or
     *                       added as a parallel worker); only a named database can be the own-scratch one
     * @return string|null a refusal reason, or null when the drop may proceed
     */
    public function refuse(string $name, string $connection, string $prefix, bool $force, ?string $ownScratch = null, bool $named = false): ?string
    {
        if ($this->app->environment('production')) {
            return 'refusing to drop any database while the application environment is [production]. '
                .'This is not overridable by --force.';
        }

        $configured = app(ServerConnection::class)->databaseFor($connection);

        if ($configured !== null && $name === $configured) {
            if ($ownScratch === null) {
                return "[{$name}] is the database connection [{$connection}] is configured to use. "
                    .'Refusing to drop the connection out from under the application. If it is a scratch '
                    .'database this host was pointed at, name it with --own-scratch=<prefix> (pgsql only).';
            }

            if ($reason = $this->refuseOwnScratch($ownScratch, $connection, $prefix, $named)) {
                return $reason;
            }

            if (! str_starts_with($name, $ownScratch)) {
                return "[{$name}] is the database connection [{$connection}] is configured to use, and it does "
                    ."not start with the --own-scratch prefix [{$ownScratch}]. Refusing to drop the connection "
                    .'out from under the application.';
            }

            // Allowed past this rule only. Rule 3 holds by construction ($ownScratch extends $prefix,
            // so --force widens nothing here) and rule 4 below still refuses any other session.
        }

        if (! $force && ! str_starts_with($name, $prefix)) {
            return "[{$name}] does not start with the scratch prefix [{$prefix}]. "
                ."Rename it, change config('beam.dev.prefix'), or pass --force if you are certain.";
        }

        $active = $this->databases->activeConnections($name, $connection);

        if ($active > 0) {
            return "[{$name}] has {$active} active connection(s) — most likely another test run. "
                .'Refusing to drop it mid-run; --force does not override this. Wait for that run to '
                .'finish, or drop it explicitly from a session that owns it.';
        }

        return null;
    }

    /**
     * Whether `--own-scratch=<prefix>` may be used at all on this connection: asked by the command
     * before it lists anything, and again by {@see refuse()} for the connection's own database.
     *
     * The prefix is the CALLER's confinement and can only narrow: it must be lowercase identifier
     * characters (no LIKE wildcard; matching is a literal starts-with), extend both the `test_`
     * namespace and the configured scratch prefix, and be strictly longer than either, so a caller
     * cannot hand in `test_` (every scratch name) or a non-test name. pgsql only: there beam-dev drops
     * from its own maintenance session on `postgres`, and the server itself refuses a DROP while any
     * session is connected. mysql/mariadb and sqlite keep rule 2 unchanged.
     *
     * @param  bool  $named  whether the database(s) were named explicitly; a sweep (`--pattern`, `--all`)
     *                       or a parallel-worker expansion never reaches the connection's own database
     * @return string|null a refusal reason, or null when the option is usable
     */
    public function refuseOwnScratch(string $ownScratch, string $connection, string $prefix, bool $named = true): ?string
    {
        if (! $named) {
            return '--own-scratch applies to databases named explicitly; a --pattern or --all sweep, or a '
                .'parallel-worker expansion, never drops the database the connection is configured to use.';
        }

        if (! preg_match('/^[a-z0-9_]{1,63}$/D', $ownScratch)) {
            return "--own-scratch prefix [{$ownScratch}] must be 1-63 characters of [a-z0-9_]: it is matched "
                .'literally, and a wildcard or other character could widen it.';
        }

        foreach (array_unique(array_filter([self::OWN_SCRATCH_NAMESPACE, $prefix])) as $required) {
            if (! str_starts_with($ownScratch, $required) || strlen($ownScratch) <= strlen($required)) {
                return "--own-scratch prefix [{$ownScratch}] must extend [{$required}] and be longer than it "
                    .'(e.g. '.self::OWN_SCRATCH_NAMESPACE.'space_): it may only narrow the scratch namespace, never '
                    .'widen it.';
            }
        }

        $driver = app(ServerConnection::class)->driverFor($connection);

        if ($driver !== 'pgsql') {
            return "--own-scratch is supported on pgsql only; connection [{$connection}] is [{$driver}], where "
                .'the connection\'s own database is never dropped.';
        }

        return null;
    }

    /** The namespace every --own-scratch prefix must extend, whatever beam.dev.prefix says. */
    public const OWN_SCRATCH_NAMESPACE = 'test_';
}
