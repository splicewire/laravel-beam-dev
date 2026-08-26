<?php

namespace Splicewire\Beam\Dev\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Dev\Databases\DropGuard;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;
use Throwable;

/**
 * Drop one scratch database, several, or every one matching a pattern.
 *
 * The cleanup half of {@see IsolatedTestDbCommand}. Isolation only stays cheap if reaping is cheap
 * too — otherwise scratch databases accumulate until someone writes a careful one-off `DROP` loop
 * under time pressure, which is exactly the situation in which the wrong database gets named.
 *
 * Every drop goes through {@see DropGuard}. A refusal names the database and the reason, and the
 * command reports refusals as a non-zero exit ONLY when nothing was dropped at all — a sweep that
 * skips one busy database and drops four others has done its job.
 */
class DropDbCommand extends Command
{
    protected $signature = 'splicewire:beam:dev:drop-db
        {names?* : Database name(s) to drop}
        {--connection= : Connection whose host/credentials to borrow (default: the app default)}
        {--pattern= : SQL LIKE pattern to match instead of naming databases (e.g. test\_%)}
        {--all : Drop every database matching the configured scratch prefix}
        {--dry-run : Report what would be dropped without dropping anything}
        {--keep-workers : Do not also drop the <name>_test_<token> parallel-worker databases}
        {--force : Allow names outside the scratch prefix (never overrides the other guards)}';

    protected $description = 'Drop scratch databases, one or many, with guards';

    public function handle(ScratchDatabases $databases, DropGuard $guard): int
    {
        $connection = (string) ($this->option('connection') ?: config('database.default'));
        $prefix = (string) config('beam.dev.prefix', 'test_');

        try {
            $targets = $this->targets($databases, $connection, $prefix);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($targets === []) {
            $this->components->info('No matching databases.');

            return self::SUCCESS;
        }

        $dropped = 0;
        $refused = 0;

        foreach ($targets as $name) {
            if ($reason = $guard->refuse($name, $connection, $prefix, (bool) $this->option('force'))) {
                $this->components->warn("Skipped {$reason}");
                $refused++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("Would drop <comment>{$name}</comment>.");
                $dropped++;

                continue;
            }

            try {
                $databases->drop($name, $connection)
                    ? $this->components->info("Dropped {$name}")
                    : $this->components->warn("Skipped [{$name}] — it does not exist.");
                $dropped++;
            } catch (Throwable $e) {
                $this->components->error("Failed to drop [{$name}]: {$e->getMessage()}");
                $refused++;
            }
        }

        // Refusals alone are a failure; a partial sweep is not. Skipping a database another run is
        // using is the guard working, and should not fail a cleanup step in CI that dropped the rest.
        return $dropped === 0 && $refused > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function targets(ScratchDatabases $databases, string $connection, string $prefix): array
    {
        $names = (array) $this->argument('names');

        if ($names !== []) {
            $named = array_values(array_map('strval', $names));

            return $this->option('keep-workers')
                ? $named
                : $this->withParallelWorkers($databases, $connection, $named);
        }

        if ($pattern = $this->option('pattern')) {
            return $databases->names($connection, (string) $pattern);
        }

        if ($this->option('all')) {
            // Escape the underscore: unescaped it is LIKE's single-character wildcard, so a prefix of
            // `test_` would also match `tests1`, `testx`, and anything else five characters in.
            return $databases->names($connection, str_replace('_', '\_', $prefix).'%');
        }

        throw new \InvalidArgumentException(
            'Name at least one database, or pass --pattern / --all. Refusing to guess what to drop.'
        );
    }

    /**
     * Expand each named database to include the parallel-worker databases derived from it.
     *
     * Laravel's parallel testing gives each worker `<database>_test_<token>` — and so does any host
     * bootstrap that agrees with that convention. They are children of the database you named: you
     * did not choose their names, you will not remember how many were made, and nothing else will
     * ever reap them. Reaping the parent without them is how a `--all` sweep months later turns up a
     * dozen strays nobody can attribute.
     *
     * Only databases that actually exist are added, so this never turns a clean drop into a
     * "does not exist" warning, and every guard still applies to each one individually — a worker
     * another run is live on is skipped exactly like any other busy database.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function withParallelWorkers(ScratchDatabases $databases, string $connection, array $names): array
    {
        $targets = [];

        foreach ($names as $name) {
            $targets[] = $name;

            // Escape both underscores: unescaped, LIKE reads each as a single-character wildcard.
            $like = str_replace('_', '\_', $name).'\_test\_%';

            foreach ($databases->names($connection, $like) as $worker) {
                $targets[] = $worker;
            }
        }

        return array_values(array_unique($targets));
    }
}
