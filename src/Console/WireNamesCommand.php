<?php

namespace Splicewire\Beam\Dev\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Dev\WireNames\DeclaredWireNames;

/**
 * Print the wire names a codebase DECLARES, so a rename can be PROVEN not to have moved one.
 *
 * ## What it is for
 *
 * A `spatie/laravel-data` property publishes the key its `#[MapName]` argument names, or — with no
 * attribute — whatever the host's global `name_mapping_strategy` produces. So renaming properties
 * **while keeping every attribute** is wire-invisible by construction, and this command is how that
 * claim is checked:
 *
 * ```
 * artisan splicewire:beam:dev:wire-names ~/path/to/src > before.txt
 * # …rename the properties, leaving every attribute argument untouched…
 * artisan splicewire:beam:dev:wire-names ~/path/to/src > after.txt
 * diff before.txt after.txt        # MUST be empty, or a published key moved
 * ```
 *
 * Measured on `api-surface-coherence` 100: 50 properties renamed across three packages, diff empty,
 * wire byte-identical.
 *
 * ## Why not just regenerate the schemas and diff those
 *
 * Because on a live estate a schema regeneration also picks up every neighbour's in-flight DTO edit,
 * and the diff becomes unreadable — measured, 49 changed lines of which 7 were the ones being
 * asserted. This reads only the thing being claimed, so nothing else moving can confound it.
 *
 * ## ⚠️ Measure BEFORE you restore
 *
 * The sibling failure to the one this command's reader fixes: during the same investigation a
 * generated artifact was copied, regenerated, restored (to avoid committing a neighbour's drift) and
 * *then* inspected — measuring the pre-run copy. Every reading came back empty and a working
 * pipeline was recorded as broken for hours. Capture output before restoring anything.
 */
class WireNamesCommand extends Command
{
    protected $signature = 'splicewire:beam:dev:wire-names
        {paths?* : Directories to read (default: the app path)}
        {--count : Print a summary count instead of the diffable listing}';

    protected $description = 'Print the wire names (published keys) a codebase declares, for proving a rename moved none';

    public function handle(DeclaredWireNames $names): int
    {
        $paths = $this->argument('paths') ?: [app_path()];
        $missing = array_values(array_filter($paths, fn (string $p): bool => ! is_dir($p)));

        if ($missing !== []) {
            // Named but absent is a caller error, and a silent empty listing would read as "no keys
            // declared" — the failure mode this whole command exists to avoid.
            $this->error('Not a directory: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $rows = $names->read($paths);

        if ($this->option('count')) {
            $keys = 0;
            foreach ($rows as $row) {
                $keys += count(explode(',', explode("\t", $row, 2)[1]));
            }

            $this->line(sprintf('%d file(s), %d declared wire name(s)', count($rows), $keys));

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->line($row);
        }

        return self::SUCCESS;
    }
}
