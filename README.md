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
| `--no-init` | Skip provisioning entirely and leave the database bare |
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
    'init'           => null,   // null = the packaged extension SQL; [] = bare
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

It takes three values, and the difference between two of them is the whole thing:

| value | what happens |
| --- | --- |
| `null` *(default)* | Run the canonical extension SQL this package ships — `uuid-ossp`, `citext`, `pg_trgm`, `fuzzystrmatch`, `vector` — **statement by statement**. Postgres only. An extension the server does not have is *named*, with what to install, and everything else is still provisioned. |
| `['path/to.sql', …]` | Your files, run whole and in order. A failure is fatal: you declared a requirement, so a database that cannot meet it is not the database you asked for. |
| `[]` | Nothing. A bare database. `--no-init` says the same for one run. |

⚠️ **This defaulted to `[]` until 2026-08-30, and the change is a measurement rather than a
preference.** `splicewire/tower`'s suite against a bare scratch database read
`Tests: 465 failed, 412 passed` — every one of the 465 `type "vector" does not exist`. Provisioned,
the same commit reads 10. The command printed a line saying the database was bare and that did not
stop two sessions reading the red as a regression, because a bulk failure naming a *migration* does
not look like a missing setup step. A default whose ordinary output is 465 false regressions is not
conservative.

`pgcrypto` is deliberately not in the packaged list. The only thing usually wanted from it is
`gen_random_uuid()`, which has been core PostgreSQL since 13 — verified on a bare database with zero
extensions on PostgreSQL 17, 2026-08-30. Every extension in that file is one more thing a server can
fail to have.

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

## Three outcomes, and only one of them makes a database

The command answers one question — *will the env line I am about to print actually reach the suite?* —
and there are three honest answers. Two of them create nothing.

**Isolated.** The normal path: a scratch database, proved reachable, and the env that targets it.

**Already isolated — nothing to do.** Your phpunit config pins an in-memory database. That suite is
isolated by construction: it lives and dies inside one process and no concurrent run can reach it. A
scratch database here would be one nothing ever opens, so none is made.

**Refused.** Something would swallow the override. The connection can't hold a scratch database, the
driver isn't one your harness opens, or — the case below — your phpunit config pins the variable with
`force="true"`. All three checks run *before* `CREATE DATABASE`, so a refusal never leaves a real
database on a real server for someone else to wonder about.

## Pinned variables in phpunit.xml

A hardcoded `<env name="DB_DATABASE" value="…"/>` may or may not defeat a shell override, and the rule
is exact rather than a judgement call. It is one line of PHPUnit, `PhpHandler::handleEnvVariables()`:

```php
if ($force || getenv($name) === false) { putenv("{$name}={$value}"); }
```

So a pin **without** `force` cannot touch a value you exported, and a pin **with** `force="true"`
discards it unconditionally. Measured on PHPUnit 12.5.33 with both variables exported: the unforced
pin read back `FROM_SHELL`, the forced pin read back `PINNED_IN_XML`.

The command applies exactly that rule. A forced pin is a refusal naming the file, the value and the
three repairs. An unforced pin is reported and stepped over:

```
  (your phpunit config pins DB_DATABASE=audiostud_testing in phpunit.xml — without force="true"
   PHPUnit keeps the shell value, so the override above still wins)
```

That line exists because the opposite inference is so easy to make. Open `phpunit.xml`, find your
variable hardcoded, conclude the override cannot possibly work — correct reasoning from incomplete
information, and it has already been drawn once against a run that was working fine.

## An empty base database is what a working parallel run looks like

`--parallel` derives one database per worker from the base name — `<base>_test_1`, `_test_2`, … — and
migrates into *those*. The base is left **empty**. So checking the database you just created, finding
no tables, and concluding the override was ignored gets it exactly backwards: the data is one name
over. The command says so up front:

```
  (running --parallel? the workers use test_ab12cd34_test_1, test_ab12cd34_test_2, … and
   test_ab12cd34 itself stays EMPTY. An empty base database is what a working parallel run looks
   like, not a failed override.)
```

Worth knowing: the derivation is `<base>_test_<token>`, so the worker names are only as unique as the
base. Two concurrent runs sharing a base name share **every** worker database with each other — which
is the argument for a session-scoped base, i.e. for this command. `drop-db <base>` reaps the children
with the parent.

## Measure what breaks first — `load`

Drive one declared operation at a controlled concurrency and get honest latency percentiles, queries
per run, error rate, and what the database server was carrying while it ran.

```bash
# what does this host declare?
artisan splicewire:beam:dev:load

# 200 runs across 8 worker processes, as JSON
artisan splicewire:beam:dev:load particle.fragments.index \
    --iterations=200 --concurrency=8 --json
```

**Nothing is bound by default, and that is deliberate.** The set of operations worth measuring is a
fact about the host's own declared surface, so beam-dev takes it through a port —
`Splicewire\Beam\Dev\Load\LoadTargetSource` — and ships no implementation. Reading a family
registry from here would make the family's development tool depend upward on the family.

Unbound, the command **fails and says so**. It does not report an empty run. "No adapter installed"
and "this host declares no operations" must not render identically, and the two branches are
separately tested.

### What the numbers mean, stated rather than assumed

- **Percentiles are nearest-rank**, and the method is printed in every reading. "p95" is not one
  definition, and the variants disagree on small samples by more than a tuning session is usually
  chasing. Nearest-rank always returns a value some run actually took — an interpolated p99 is a
  number no request ever took.
- **Durations are integer nanoseconds** from `hrtime(true)`, a monotonic counter. Not `microtime()`,
  whose float resolution decays as the epoch grows, and emphatically not a second-precision timestamp
  column: this estate has one of those exposing a `duration_ms` accessor, and it returns milliseconds
  that are always multiples of 1000 while never looking broken.
- **Errors are excluded from latency and reported as a rate.** A failing request is usually fast, so
  folding errors into the latency set makes a degrading system look like it is speeding up.
- **Concurrency is real processes.** The parent spawns workers of itself via `proc_open`; PHP has no
  in-process concurrency worth measuring against. A worker that exits non-zero **fails the whole run**
  rather than quietly contributing no samples — a dead worker would flatter every percentile computed
  from the survivors.

### ⚠️ Read the connection counts before you read the latency

Every reading carries `db_connections_before` / `_peak` / `_after`, sampled from the server
(`pg_stat_activity`), not from this process's pool — a per-process count would report 1 while eight
sibling workers saturated the cluster.

At concurrency of 8 and up against one local server, **the thing you are measuring is the cluster,
not the application.** This estate has that measurement already, in its loud form: two concurrent
schema-creating suites exhausted one local Postgres with `out of shared memory`, killing ~60 tests
including pure unit tests that touch no database, and the run read *196 failed* against a true figure
of ~132. A load run is the same contention with the failure mode inverted — instead of phantom
failures you get plausible latency, which is worse, because nothing about it looks wrong.

A count of `null` means the driver could not be asked (sqlite has no server), never that the answer
was zero.

## Check the run finished — `witness-run`

Isolating the database doesn't make the run readable. A suite can stop partway, print the failures it
had so far, and leave output that reads exactly like a result. Four causes are on record, and they do
**not** share an exit code:

| cause | exit |
| --- | --- |
| PHP dying on `memory_limit` mid-suite | **0** |
| the runner piped through `head` — `SIGPIPE` tears the run down | **0** |
| a paratest worker segfaulting under `--parallel` | 1 |
| the `artisan test` wrapper killed, leaving an orphaned `pest` child | varies |

So neither the exit code nor the failure list tells you the suite finished. The one thing every
finished run emits is a **summary line**, and `witness-run` is the check:

```sh
# run it, and refuse to report a pass for a run that did not finish
php artisan splicewire:beam:dev:witness-run -- php -d memory_limit=2G vendor/bin/pest

# or witness a log you already have (a CI step keeping its own invocation)
php artisan splicewire:beam:dev:witness-run --assert=build/test.log --exit-code=$?
```

Three outcomes. **finished** returns the runner's own exit code unchanged — the command adds nothing
to a run that behaved. **truncated** (no summary) exits 1 whatever the runner exited on, naming the
four causes. **disagrees** (a summary that contradicts the exit code) exits 1 too; nothing else in
this estate checks that direction.

It never pipes the runner through `head`, `tail` or `grep`, and it reads the exit code off the
process rather than off a pipeline — one of the four causes *is* that pipeline, and reading `$?`
after `| tail` is how a crash gets written down as exit 0.

`isolated-test-db` still warns when PHP's limit is under 512M, before the run; this is the check
after it.

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
