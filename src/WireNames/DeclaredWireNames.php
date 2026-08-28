<?php

namespace Splicewire\Beam\Dev\WireNames;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Reads the wire names a codebase DECLARES — every key named by a `#[MapName]` / `#[MapInputName]` /
 * `#[MapOutputName]` attribute, keyed by the file that declares it.
 *
 * The published key of a `spatie/laravel-data` property is the attribute's argument when one is
 * present, and whatever the host's global `name_mapping_strategy` produces when it is not. So a
 * refactor that renames PHP properties **while keeping every attribute** cannot move a published
 * key — and this reader is how that is checked rather than assumed.
 *
 * ⚠️ **Comments are stripped first, and that is not cosmetic.** The first version of this check did
 * not strip them and matched the attribute inside docblocks *explaining the convention*: a file
 * illustrating `#[MapInputName('expires_in_days')]` reported the key twice, and one writing
 * `#[MapInputName('<snake_key>')]` generically reported a key literally named `<snake_key>`.
 * Mid-refactor the output read as a botched rename and was a botched reader.
 */
class DeclaredWireNames
{
    private const ATTRIBUTE = "/#\\[Map(?:Input|Output)?Name\\('([^']+)'\\)\\]/";

    /**
     * @param  list<string>  $roots  directories to walk
     * @return list<string> `<path>\t<comma-separated sorted keys>`, sorted — diffable as-is
     */
    public function read(array $roots): array
    {
        $rows = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach ($this->phpFilesUnder($root) as $path) {
                $source = @file_get_contents($path);

                if ($source === false) {
                    continue;
                }

                preg_match_all(self::ATTRIBUTE, $this->codeOnly($source), $matches);

                if ($matches[1] !== []) {
                    $keys = $matches[1];
                    sort($keys);
                    $rows[] = $path."\t".implode(',', $keys);
                }
            }
        }

        sort($rows);

        return $rows;
    }

    /** Every declared key across the roots, deduped — for a count rather than a diff. */
    public function keys(array $roots): array
    {
        $keys = [];

        foreach ($this->read($roots) as $row) {
            [, $csv] = explode("\t", $row, 2);
            foreach (explode(',', $csv) as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Strip comments and docblocks so only CODE is scanned — see the class docblock for the defect
     * this prevents. `token_get_all()` rather than a regex, because a regex that strips comments is
     * the same class of mistake one layer down.
     */
    private function codeOnly(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * @return iterable<string>
     */
    private function phpFilesUnder(string $root): iterable
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
