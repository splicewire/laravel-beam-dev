# laravel-beam-dev

Session-scoped scratch databases for Laravel, so concurrent test runs stop clobbering each other —
and so cleaning up afterwards isn't a hand-written `DROP DATABASE` loop.

Despite the name it depends on nothing from the beam family. It's a plain Laravel package.

```bash
composer require --dev splicewire/laravel-beam-dev
```

## The problem

Two test runs sharing one database wreck each other. A schema rebuild, a `RefreshDatabase`, a
truncate, or a terminated connection in one run yanks the database out from under the other
mid-suite. What you see is rows vanishing, phantom unique collisions, and rollbacks that can't reach
the server — failures that look exactly like real regressions in whatever you were working on. People
lose hours to it, and the tell ("passes alone, fails in a full run") is easy to misread as flakiness.

The fix is a database per **session** — not per run; see [One database per session](#one-database-per-session-not-per-run).
The reason people don't bother is that doing it by hand is fiddly in four separate ways, and getting
three of them right still leaves you with a broken setup.

## Usage

```bash
php artisan splicewire:beam:dev:isolated-test-db
```

```
Created test_9f2ab41c on connection pgsql.
Provisioned: extensions.sql

Run your suite against it:
  TEST_DB_DATABASE=test_9f2ab41c DB_DATABASE=test_9f2ab41c php artisan test

Reap it when the run is done:
  php artisan splicewire:beam:dev:drop-db test_9f2ab41c
```

Then clean up — one, several, or a sweep:

```bash
php artisan splicewire:beam:dev:drop-db test_9f2ab41c
php artisan splicewire:beam:dev:drop-db test_one test_two test_three
php artisan splicewire:beam:dev:drop-db --all --dry-run
php artisan splicewire:beam:dev:drop-db --pattern='test\_ci\_%'
```

### One database per session, not per run

Called bare, the command mints a **random** name, so calling it twice hands you two databases — two
cold migrations to pay for and two to remember to reap. Name it instead, and every later call in the
session reuses the one you already have:

```bash
php artisan splicewire:beam:dev:isolated-test-db --slug=$MY_SESSION_ID
# first call  → Created test_<id> on connection pgsql.
# every later → Reusing existing test_<id> on connection pgsql.
```

That is the whole difference between isolation being free and isolation being a tax. Measured in
`splicewire/splicewire-app` (246 migrations across central, package and tenant paths): a **cold**
database costs ~2.5s to migrate, a **reused** one ~0.2s to re-enter — but only if the project's test
harness notices it is already migrated. Most do not: a `RefreshDatabase`-style setup drops and
re-migrates on entry regardless, and then reuse buys nothing. If your harness rebuilds unconditionally,
that is the thing to fix; this command cannot fix it from outside, because only the harness knows
whether the schema on disk still matches the schema in the database.

`--drop-existing` is the opt-out when you do want a virgin database under the same name.

### Parallel workers are reaped with their parent

Laravel's parallel testing gives each worker its own database, named `<database>_test_<token>`. You
never chose those names, you will not remember how many were made, and nothing else will ever reap
them — so `drop-db` takes them down with the database they hang off:

```bash
php artisan splicewire:beam:dev:drop-db test_<id> --dry-run
# Would drop test_<id>.
# Would drop test_<id>_test_1.
# … through _test_4
```

Every guard still applies to each one individually, so a worker database another run is live on is
skipped exactly like any other busy database. `--keep-workers` opts out.

Note the framework creates those databases but does **not** run `--init` provisioning against them,
so on a project whose schema needs extensions installed first, `--parallel` fails inside the first
migration that needs one — and blames the migration. The fix belongs in the host's test bootstrap
(give each worker process a provisioned database and set
`LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES`), not here: by the time this package's commands could
run, the worker is already booted.

### Options

| option | |
| --- | --- |
| `--connection=` | Borrow host/port/credentials from this connection (default: the app default) |
| `--name=` / `--slug=` | Set the database name outright, or just its suffix |
| `--init=` | SQL file(s) to run inside the new database before anything migrates |
| `--var=` | Extra env var names to emit, beyond `config('beam.dev.env')` |
| `--drop-existing` | Recreate if it already exists |
| `--dry-run` | (drop) Report what would go, change nothing |
| `--keep-workers` | (drop) Leave the `<name>_test_<token>` parallel-worker databases in place |
| `--force` | (drop) Allow names outside the scratch prefix — see the guards |

`--connection` is how credentials work: the DSN your project already configured is the one known to
work, so the scratch database is provisioned with exactly the access the app itself has. There's
nothing new to configure and nothing to keep in sync.

## Configuration

```php
// config/beam/dev.php
return [
    'prefix' => env('BEAM_DEV_DB_PREFIX', 'test_'),
    'env'    => ['DB_DATABASE'],
    'init'   => [],
];
```

**`env` is the one to get right.** List *every* variable that must point at the scratch database, not
just the obvious one. It's common for a test connection to read `TEST_DB_DATABASE` while other
connections on the same server read `DB_DATABASE` — override only the first and part of your app is
still talking to the shared database, so the isolation silently does nothing. If you have a second
connection (a `central` alongside a tenant one, a reporting replica, a queue database), name its
variable here too.

**`init` is the other.** A bare `CREATE DATABASE` looks fine, and then the first migration needing
`citext`, `vector` or a role dies with an error about the extension rather than about the missing
setup step. Point this at the SQL your schema assumes has already run.

## The guards

`drop-db` destroys whole databases, so every drop goes through one guard with four rules:

1. **Never in production.** Not behind `--force`, not behind a prompt. A switch that can delete a
   production database is one someone eventually flips in the wrong shell.
2. **Never the connection's own database.** The database your app is configured to use is the one
   thing on that server that certainly isn't scratch.
3. **Only names matching the prefix**, unless `--force`. That prefix is the line between "a throwaway
   this tool made" and "someone's database that happens to live on the same server".
4. **Never one with live sessions.** A scratch database with another process connected is somebody
   else's test run. `--force` does *not* override this one — force is for naming things outside the
   prefix, not for pulling a database out from under a running suite.

Naming nothing is an error rather than a sweep. "Drop everything" is never the safe default for a
command whose whole job is deletion.

Database names can't be parameter-bound, so they're validated against `[A-Za-z0-9_]{1,63}` and
rejected outright rather than escaped.

## Engines

PostgreSQL, MySQL/MariaDB, and SQLite (where a scratch database is a file, and `--init` doesn't
apply).

## Beyond testing

The primitive here — *provision a named, disposable database from an existing connection's
credentials, optionally running SQL into it first* — isn't only a test-suite thing. The same two
commands stand up and tear down per-branch review environments, or a demo instance that resets on a
schedule. That's why the commands aren't namespaced under `test`, and why they register in any
non-production environment rather than only under `require-dev`.

## License

MIT.
