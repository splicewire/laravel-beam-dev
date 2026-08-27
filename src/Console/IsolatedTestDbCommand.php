<?php

namespace Splicewire\Beam\Dev\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Splicewire\Beam\Dev\Databases\IsolationGuard;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;
use Splicewire\Beam\Dev\Databases\ServerConnection;
use Splicewire\Beam\Dev\Databases\SuiteHarness;
use Throwable;

/**
 * Provision a scratch database nobody else is using, and print the env that points a test run at it.
 *
 * The problem this exists to delete: two test runs sharing one database wreck each other. A schema
 * rebuild, a `RefreshDatabase`, a truncate or a terminated connection in one run yanks the database
 * out from under the other mid-suite, and the failures that produces — vanished rows, phantom unique
 * collisions, a rollback that cannot reach the server — read exactly like real regressions. Hours go
 * into chasing a code bug that was never there.
 *
 * The fix is per-session isolation, and the only reason it usually isn't done is that doing it by
 * hand is fiddly: derive a name, create the database, run whatever SQL the schema assumes has
 * already run, and remember which env var the test runner actually reads. This command does all
 * four, idempotently.
 *
 * ⚠️ **Provisioning is opt-in and this command does not guess it.** Extensions (`vector`,
 * `uuid-ossp`, `pg_trgm`, …), roles and search paths are run only from the SQL files listed in
 * `config('beam.dev.init')` or passed as `--init`. The default is an empty list, so a bare invocation
 * creates a BARE database — correct, reachable, and with no extensions in it. A project whose
 * migrations need one says so once in config; until it does, the first migration that needs an
 * extension is where it will find out. The command says as much on the way past.
 *
 * Three honesty rules it holds, all of them repairs of a measured defect (2026-08-27):
 *
 * - It refuses a connection it cannot isolate on — see {@see IsolationGuard}.
 * - It never reports a database it has not just proved reachable. `create()` returning true means a
 *   statement was issued, not that a suite can connect; those are different claims and only the
 *   second one is worth printing.
 * - It reads the project's test harness rather than assuming the running config describes it — see
 *   {@see SuiteHarness}. Under a package testbench the two are routinely different objects, and the
 *   command used to print an env line for a variable the suite never reads.
 */
class IsolatedTestDbCommand extends Command
{
    protected $signature = 'splicewire:beam:dev:isolated-test-db
        {--connection= : Connection whose host/credentials to borrow (default: the app default)}
        {--name= : Full database name to use, overriding prefix+slug}
        {--slug= : Suffix appended to the prefix (default: a random one)}
        {--init=* : SQL file(s) to run inside the new database before anything migrates}
        {--var=* : Extra env var names to emit pointing at the database, beyond config}
        {--any-driver : Proceed even though the project\'s test harness pins a different driver}
        {--drop-existing : Drop and recreate if it already exists}';

    protected $description = 'Create a session-scoped scratch database, verify it is reachable, and print the env that targets it (provisioning SQL is opt-in via config)';

    public function handle(
        ScratchDatabases $databases,
        ServerConnection $server,
        IsolationGuard $isolation,
        SuiteHarness $harness,
    ): int {
        $connection = (string) ($this->option('connection') ?: config('database.default'));
        $name = $this->resolveName();

        if ($reason = $isolation->refuse($connection)) {
            $this->components->error($reason);

            return self::FAILURE;
        }

        if (! $this->option('any-driver') && $reason = $harness->refuse($connection, $server->driverFor($connection))) {
            $this->components->error($reason);

            return self::FAILURE;
        }

        try {
            if ($this->option('drop-existing') && $databases->exists($name, $connection)) {
                $databases->drop($name, $connection);
                $this->line("Dropped existing <comment>{$name}</comment>.");
            }

            $created = $databases->create($name, $connection);

            // Prove it before announcing it. Reporting off create()'s return value is what let this
            // command print "Created test_k2uauqv7" for a database that never existed.
            $databases->assertReachable($name, $connection);

            $this->line($created
                ? "Created <info>{$name}</info> on connection <comment>{$connection}</comment>."
                : "Reusing existing <info>{$name}</info> on connection <comment>{$connection}</comment>.");

            $init = $this->initFiles($harness);

            if ($init !== []) {
                $databases->runSqlFiles($name, $connection, $init);
                $this->line('Provisioned: '.implode(', ', array_map('basename', $init)));
            } else {
                // Say what was NOT done, so nobody infers extensions from the word "Created".
                $this->line('No provisioning SQL configured — the database is bare. '
                    .'Extensions and roles come from <comment>config(\'beam.dev.init\')</comment> or <comment>--init</comment>.');
            }
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->emitEnv(
            $harness,
            $databases->targetValue($name, $connection),
            $name,
            $connection,
            $server->databaseFor($connection),
        );

        return self::SUCCESS;
    }

    private function resolveName(): string
    {
        if ($explicit = $this->option('name')) {
            return (string) $explicit;
        }

        $prefix = (string) config('beam.dev.prefix', 'test_');
        $slug = (string) ($this->option('slug') ?: Str::lower(Str::random(8)));

        return $prefix.preg_replace('/[^A-Za-z0-9_]/', '_', $slug);
    }

    /**
     * @return list<string>
     */
    private function initFiles(SuiteHarness $harness): array
    {
        $files = $this->option('init') ?: config('beam.dev.init', []);

        return array_values(array_map(
            static fn ($path) => $harness->resolveProjectPath((string) $path, base_path()),
            (array) $files,
        ));
    }

    /**
     * Emit every env var that must point at the scratch database — not just the obvious one.
     *
     * This is the step that is easy to get half-right by hand and is the reason isolation "doesn't
     * work" when someone tries it. A project often reads its test database from one variable while
     * other connections on the same server read another, so overriding only the first still leaves
     * part of the app talking to the shared database.
     *
     * There are now two sources for the list and they are UNIONED, never chosen between:
     * `config('beam.dev.env')` is what the project declared, and {@see SuiteHarness} is what its test
     * files are observed to read. Tower declared nothing and read `TEST_DB_DATABASE`; the old
     * config-only list emitted `DB_DATABASE` and the suite ignored it completely. Setting a variable
     * the suite happens to ignore costs nothing; failing to set one it reads is the entire defect.
     *
     * $value is what the variable must be SET to and $name is what the database is CALLED — the same
     * string on a server engine, a path and a name on SQLite. Emitting the name where the value
     * belongs is how an isolated run silently lands back on a database that does not exist.
     */
    private function emitEnv(
        SuiteHarness $harness,
        string $value,
        string $name,
        string $connection,
        ?string $replacing,
    ): void {
        $vars = $harness->targetEnvVars(array_map('strval', (array) $this->option('var')));
        $assignments = implode(' ', array_map(static fn ($var) => "{$var}={$value}", $vars));

        $this->newLine();
        $this->reportEnvProvenance($harness);

        $this->line('Run your suite against it:');
        $this->line("  <info>{$assignments}</info> php artisan test");

        if ($replacing !== null) {
            $this->newLine();
            $this->line("  (those override <comment>{$replacing}</comment> for this run only — nothing on disk changes)");
        }

        $this->warnAboutMemoryLimit();

        $this->newLine();
        $this->line('Reap it when the run is done:');
        $this->line('  <info>php artisan splicewire:beam:dev:drop-db '.$name
            .($connection === (string) config('database.default') ? '' : " --connection={$connection}").'</info>');
    }

    /**
     * Say where the emitted variable list came from, every time.
     *
     * A tool that silently guesses right is indistinguishable from one that silently guesses wrong,
     * and this one guessed wrong for months. Naming the files the extra variables were read out of
     * makes the claim checkable in one `cat`; saying "no evidence found" when the scan came back
     * empty makes the fallback visible rather than confident.
     */
    private function reportEnvProvenance(SuiteHarness $harness): void
    {
        $discovered = $harness->undeclaredEnvVars();

        if ($discovered === []) {
            $sources = $harness->sources();

            $this->line($sources === []
                ? '<comment>No test-harness files were found to read env var names from</comment> — emitting the configured list only.'
                : '<comment>No database env var beyond the configured list was found in</comment> '.implode(', ', $sources).'.');

            $this->newLine();

            return;
        }

        $this->line('Read from this project\'s test harness: <info>'.implode(', ', $discovered).'</info> '
            .'(in '.implode(', ', $harness->sources()).').');
        $this->line('Add to <comment>config(\'beam.dev.env\')</comment> to declare rather than rediscover.');
        $this->newLine();
    }

    /**
     * PHP's default `memory_limit` is 128M, and a large suite that hits it dies MID-RUN: it prints
     * the failures it had so far, emits no summary line, and exits 0. That reads as a pass to
     * anything automated and as an ordinary partial failure list to a human — so the run this command
     * just isolated can still be measured wrong for a reason that has nothing to do with the database.
     *
     * Warn from the actual value rather than unconditionally, so the note carries information.
     */
    private function warnAboutMemoryLimit(): void
    {
        $limit = $this->memoryLimitBytes();

        if ($limit === null || $limit >= 512 * 1024 * 1024) {
            return;
        }

        $this->newLine();
        $this->line('  <comment>⚠ PHP memory_limit is '.ini_get('memory_limit').'.</comment> A large suite can die on '
            .'memory mid-run, print its failures, emit NO summary line, and still exit 0. Run with '
            .'<info>php -d memory_limit=2G</info>, and check for the run summary (pest/phpunit print a '
            .'`Tests:` line; a JSON reporter prints a final object) before believing any result.');
    }

    private function memoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
