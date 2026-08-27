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
| `--var=` | Extra env var names to emit, beyond `config('beam.dev.env')` and what the harness scan found |
| `--any-driver` | Proceed even though your test harness pins a different driver |
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
    'prefix'         => env('BEAM_DEV_DB_PREFIX', 'test_'),
    'env'            => ['DB_DATABASE'],
    'harness_paths'  => null,   // null = cwd + base path; [] = disable discovery
    'sqlite_dir'     => env('BEAM_DEV_SQLITE_DIR'),
    'init'           => [],
];
```

**`env` is the one to get right.** List *every* variable that must point at the scratch database, not
just the obvious one. It's common for a test connection to read `TEST_DB_DATABASE` while other
connections on the same server read `DB_DATABASE` — override only the first and part of your app is
still talking to the shared database, so the isolation silently does nothing. If you have a second
connection (a `central` alongside a tenant one, a reporting replica, a queue database), name its
variable here too.

You don't have to get it right for isolation to work, though — see *Reading your harness* below. This
list is how you stop rediscovering; it is not what makes the tool correct.

**`harness_paths`** is where that scan looks. `null` means the working directory plus the application
base path — both, because under `vendor/bin/testbench` the base path is the testbench *skeleton*
inside `vendor/` and the harness worth reading is in the repo you're standing in. An empty array
disables discovery.

**`sqlite_dir`** is where SQLite scratch files live: by default a project-scoped directory under the
system temp dir, deliberately *not* beside the connection's own database. Nothing this tool creates
should ever show up in your `git status`. The directory is stable rather than pid-keyed because the
drop runs in a different process than the create; the session key is the file *name*.

**`init` is the other.** A bare `CREATE DATABASE` looks fine, and then the first migration needing
`citext`, `vector` or a role dies with an error about the extension rather than about the missing
setup step. Point this at the SQL your schema assumes has already run.

⚠️ **`init` is empty by default and this package will not guess it.** With nothing configured the
command creates a *bare* database and says so (`No provisioning SQL configured — the database is
bare.`). It does not install extensions on your behalf, and "Created" has never meant "provisioned".
If you arrived here because a scratch database lacked `vector` / `uuid-ossp` / `pg_trgm`, the step
did not fail — it was never configured to run.

## Honesty about what it did

The command reports work it has *verified*, not work it *issued*. After creating, it opens a
connection pointed at the new database and runs a trivial query; only then does it print `Created`.
And it refuses a connection it cannot isolate on at all:

```
php artisan splicewire:beam:dev:isolated-test-db     # database.default is sqlite :memory:

  ERROR  Connection [testing] is an in-memory SQLite database (`:memory:`). There is nothing to
  isolate … Pass a server-backed connection instead: --connection=pgsql (configured here: pgsql, mysql).
```

That case is why this exists. Under a package testbench harness `database.default` is an in-memory
SQLite connection, and the command used to report `Created test_xxxxxxxx`, touch a stray
`test_xxxxxxxx.sqlite` in the process's working directory (`dirname(':memory:')` is `.`), and emit an
env assignment pointing at nothing — so a session that asked for isolation got a success message and
none. A refusal you can act on beats a success you cannot.

## Reading your harness

`config('beam.dev.env')` is a *declaration*. What your test files actually read is an *observation*,
and the command makes it: it scans `tests/**.php`, `phpunit.xml`, `testbench.yaml` and `.env.testing`
for any `env('*DB_DATABASE')` the harness selects its database with, and for the drivers it pins. Then
it emits the **union** of the declared list and the discovered one, and names the files it read them
from:

```
Read from this project's test harness: TEST_DB_DATABASE (in tests/TenantTestCase.php).
Add to config('beam.dev.env') to declare rather than rediscover.

Run your suite against it:
  DB_DATABASE=test_ab12cd34 TEST_DB_DATABASE=test_ab12cd34 php artisan test
```

A variable your suite ignores costs nothing. A variable your suite reads and nobody set is the whole
defect — measured in a testbench package whose `TenantTestCase` reads `env('TEST_DB_DATABASE')` while
the command printed `DB_DATABASE=`, so a session ran its "isolated" suite against the shared database
and read 48 failures that were 2 once it genuinely had its own.

The same evidence drives a refusal. Point the command at a SQLite connection while your harness pins
pgsql and it stops, rather than handing back an env line that would isolate nothing:

```
  ERROR  Connection [testing] is SQLite, but this project's test harness also pins pgsql — and a
  suite that opens a pgsql database is the one that collides with a neighbouring run …
  Evidence: tests/TenantTestCase.php. Pass --connection=<a pgsql connection>, or --any-driver …
```

Absence of evidence is never treated as evidence: when the scan finds nothing the command falls back
to the configured list and *says so* on the way past.

## Check the run finished

Isolating the database doesn't make the run readable. Two failure modes are worth knowing, because
both look like results:

- A suite that dies on **`memory_limit`** prints its failures so far, emits **no summary line, and
  exits 0**. The command warns when PHP's limit is under 512M; run large suites with
  `php -d memory_limit=2G`.
- A **segfaulted** run stops the same way.

So check for the summary before believing anything. `pest`/`phpunit` print a `Tests:` or `OK (…)`
line; a JSON reporter prints a final object. The form varies — the presence of *some* terminal
summary is what you're checking for.

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

PostgreSQL, MySQL/MariaDB, and SQLite (where a scratch database is a file, the emitted env carries
that file's **path** rather than a bare name, and `--init` doesn't apply).

In-memory SQLite is refused outright: it lives for one process, no other run can reach it, and there
is nothing there to isolate.

## Beyond testing

The primitive here — *provision a named, disposable database from an existing connection's
credentials, optionally running SQL into it first* — isn't only a test-suite thing. The same two
commands stand up and tear down per-branch review environments, or a demo instance that resets on a
schedule. That's why the commands aren't namespaced under `test`, and why they register in any
non-production environment rather than only under `require-dev`.

## License

MIT.
