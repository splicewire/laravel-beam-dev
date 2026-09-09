<?php

namespace Splicewire\Beam\Dev\Tests\Topology;

use Splicewire\Beam\Dev\Tests\TestCase;

/**
 * beam-dev's own `extra.package-topology.mustNotRequire`, with teeth — and with **no dependency on
 * `rushing/php-package-topology`**, deliberately.
 *
 * ## Why not the estate's usual idiom
 *
 * The idiom is `AssertsDeclaredTopology` + the tool as a dev dependency, as in
 * `splicewire/laravel-beam-market`. Two measurements ruled it out here (2026-09-09):
 *
 * 1. **The exemplar is a vacuous pass.** `DeclaredContractSource` builds its contract by globbing
 *    `{vendorPath}/{vendor}/{name}/composer.json` — it reads only **vendored** packages, and a package
 *    does not vendor itself. Probed directly at beam-market: its contract contains **0 rules**, over
 *    221 vendored manifests, none of which declares a topology contract. Its test asserts
 *    `violations === []` and `didNotLook === 0`, and never asserts a rule was evaluated — so it passes
 *    by not looking. Copying it here would install that.
 * 2. **The dependency cost fights the property being enforced.** The tool needs
 *    `rushing/php-graphine` too, and private path/git repository entries for each; beam-market carries
 *    **45** repository entries to make its version work. This package requires `illuminate/*` and
 *    `spatie/laravel-package-tools` and nothing else, on purpose — that minimalism is the same charter
 *    the declaration exists to protect.
 *
 * `mustNotRequire` over globs is a string test on one manifest. It does not need a graph.
 *
 * ## What this does and does not cover
 *
 * It puts **this package's own runtime `require`** on trial against **its own declared globs**, read
 * from disk rather than restated — so the assertion cannot drift from the declaration. It does not
 * evaluate dependencies' declarations, and it does not check acyclicity; nothing here needs either.
 *
 * `require-dev` is deliberately out of scope, matching the tool's own semantics: the graph is
 * hydrated from runtime `require` only (`ComposerManifestGraphSource:63`), and
 * `TopologyEvaluator:148-150` states that a `require-dev` edge is invisible to it.
 */
class OwnTopologyDeclarationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $path = __DIR__.'/../../composer.json';

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, "Could not read {$path}");

        return $decoded;
    }

    /** @return list<string> */
    private function forbiddenGlobs(): array
    {
        $globs = $this->manifest()['extra']['package-topology']['mustNotRequire'] ?? null;

        // A missing declaration must FAIL, never silently pass. "No globs" and "no violations" are
        // the same green otherwise — the estate's signature defect, in the test written to prevent it.
        $this->assertIsArray($globs, 'extra.package-topology.mustNotRequire is absent from composer.json');
        $this->assertNotEmpty($globs, 'extra.package-topology.mustNotRequire is declared but empty');

        return $globs;
    }

    private function matchesAny(string $package, array $globs): bool
    {
        foreach ($globs as $glob) {
            if (fnmatch($glob, $package)) {
                return true;
            }
        }

        return false;
    }

    public function test_the_declaration_is_present_and_names_the_family_vendors(): void
    {
        // Pinned, so silently narrowing the declaration to make a future require legal is itself a
        // failing test rather than a quiet edit.
        $this->assertSame(
            ['splicewire/*', 'schemastud/*', 'rushing/*'],
            $this->forbiddenGlobs(),
        );
    }

    public function test_no_runtime_require_matches_a_forbidden_glob(): void
    {
        $globs = $this->forbiddenGlobs();
        $requires = array_keys($this->manifest()['require'] ?? []);

        $this->assertNotEmpty($requires, 'No runtime requires read — the manifest probe is broken.');

        $violations = array_values(array_filter(
            $requires,
            fn (string $p): bool => $this->matchesAny($p, $globs),
        ));

        $this->assertSame([], $violations, sprintf(
            "beam-dev requires %s, which its own mustNotRequire forbids.\n".
            'This package is family-blind by charter: every consumer installs it as require-dev, and a '.
            'dependency upward would invert the direction the whole tier ladder is built on.',
            implode(', ', $violations),
        ));
    }

    /**
     * The teeth. Without this, the assertion above passes on an estate where the globs match nothing —
     * which is exactly how `laravel-beam-market`'s topology test passes over zero rules.
     */
    public function test_the_assertion_would_catch_a_forbidden_require(): void
    {
        $globs = $this->forbiddenGlobs();

        foreach (['splicewire/laravel-beam', 'schemastud/laravel-frame', 'rushing/laravel-popcorn'] as $planted) {
            $this->assertTrue(
                $this->matchesAny($planted, $globs),
                "A require on {$planted} would NOT be caught by the declared globs.",
            );
        }

        // And the converse, so the globs are not simply matching everything.
        foreach (['illuminate/console', 'spatie/laravel-package-tools', 'phpunit/phpunit'] as $legal) {
            $this->assertFalse(
                $this->matchesAny($legal, $globs),
                "The globs wrongly forbid {$legal}, which this package legitimately requires.",
            );
        }
    }
}
