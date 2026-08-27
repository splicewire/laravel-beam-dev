<?php

namespace Splicewire\Beam\Dev\Databases;

use Illuminate\Contracts\Config\Repository;

/**
 * What the project's TEST HARNESS says about its own database — read off the harness files, not
 * guessed from the running config.
 *
 * This exists because of the second half of a measured defect (2026-08-27), and it is the half that
 * survived the first repair. {@see IsolationGuard} stopped `isolated-test-db` from reporting a
 * scratch database on an in-memory connection. It did not stop the more general failure underneath:
 * **the command's view of "the database" and the suite's view of it are two different things, and
 * nothing was comparing them.**
 *
 * Measured in `splicewire/tower`, a testbench package:
 *
 * - `database.default` under `vendor/bin/testbench` is testbench's own skeleton `testing`
 *   connection. Tower's suite never uses it — `tests/TenantTestCase.php` BUILDS a pgsql connection at
 *   runtime inside `defineEnvironment()`.
 * - That harness reads its database name from `env('TEST_DB_DATABASE', 'test_tower')`. The command
 *   emitted `DB_DATABASE=…`, which tower's harness does not read at any point.
 *
 * So even with a server-backed connection passed by hand, the printed env line was inert. The
 * session that used it believed it was isolated, shared the estate's one named `test_tower` with a
 * concurrent flagship run, and read 48 failures that were 2 once genuinely isolated. That is the
 * estate's recurring "instrument that reports success by not running", and a tool that prints a
 * useless env line is the worst form of it: nothing fails, so nothing gets checked.
 *
 * The repair is to stop guessing. A harness is a file on disk that literally names the variables it
 * reads and the driver it pins, so this class reads them and the command emits what it found —
 * refusing outright when the driver it was pointed at is not one the harness will ever use.
 *
 * ⚠️ **Evidence, not authority.** Everything here is a regex over source files, so absence of
 * evidence is never treated as evidence: an empty result falls back to configuration and says so out
 * loud. The only thing it is allowed to do on its own is REFUSE, and only when it found positive
 * evidence that contradicts the invocation.
 */
class SuiteHarness
{
    /**
     * Any env var whose name ends in `DB_DATABASE` is a database-name variable. Deliberately broad:
     * the estate has `TEST_DB_DATABASE`, and a project with two connections has a second prefix that
     * nobody would think to configure until isolation silently failed once.
     */
    private const ENV_CALL = '/\benv\(\s*[\'"]([A-Z][A-Z0-9_]*DB_DATABASE)[\'"]/';

    /** phpunit.xml / testbench.yaml declare the same thing in XML/YAML rather than in PHP. */
    private const ENV_DECLARATION = '/<(?:env|server)\s+name=["\']([A-Z][A-Z0-9_]*DB_DATABASE)["\']/';

    private const DRIVER = '/[\'"]driver[\'"]\s*=>\s*[\'"](pgsql|mysql|mariadb|sqlite)[\'"]/';

    /** @var array{env: list<string>, drivers: list<string>, sources: list<string>}|null */
    private ?array $scan = null;

    /** @var array<string, array{value: string, force: bool, file: string}>|null */
    private ?array $pins = null;

    /**
     * @param  list<string>  $roots  directories that may hold the harness, most specific first
     */
    public function __construct(
        private Repository $config,
        private array $roots,
    ) {}

    /**
     * Env var names the harness is observed to read for its database name.
     *
     * @return list<string>
     */
    public function envVars(): array
    {
        return $this->scan()['env'];
    }

    /**
     * Drivers the harness is observed to pin.
     *
     * @return list<string>
     */
    public function drivers(): array
    {
        return $this->scan()['drivers'];
    }

    /**
     * The files the evidence came from — printed alongside any claim made from it, because a
     * heuristic that cannot be checked is just a guess with better manners.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        return $this->scan()['sources'];
    }

    /**
     * Refuse a connection whose driver the harness will never use.
     *
     * Sibling to {@see IsolationGuard::refuse()} and {@see DropGuard::refuse()}: a reason a human can
     * act on, or null. It fires ONLY on positive evidence, in two shapes:
     *
     * 1. **The driver is not one the harness names at all** — a pgsql-only harness pointed at sqlite.
     * 2. **The driver is sqlite while the harness names a SERVER engine somewhere.** This second
     *    clause is the one tower needed. A package harness routinely carries both: a light
     *    `TestCase` on sqlite for unit tests and a heavy `TenantTestCase` on pgsql for the Feature
     *    suite that actually collides with a neighbour. Membership alone would wave sqlite through on
     *    the strength of the suite that never needed isolating, and the run that did need it would
     *    land back on the shared server database — the original defect, one layer in.
     *
     * This is fatal rather than advisory on purpose, and it is the narrow case where that is allowed.
     * The estate's rule is that a check whose answer depends on the HOST must be a finding; "you
     * pointed an isolation tool at a driver this repo's own test harness never selects" is a fact
     * about the invocation, readable from the repo the caller is standing in.
     */
    public function refuse(string $connection, ?string $driver): ?string
    {
        $drivers = $this->drivers();

        if ($drivers === [] || $driver === null) {
            return null;
        }

        $servers = array_values(array_diff($drivers, ['sqlite']));

        if ($driver === 'sqlite' && $servers !== []) {
            return "Connection [{$connection}] is SQLite, but this project's test harness also pins "
                .$this->list($servers).' — and a suite that opens a '.$this->list($servers).' database '
                .'is the one that collides with a neighbouring run. A SQLite scratch file would isolate '
                .'the half that never needed it and leave the other half on the shared server database. '
                .'Evidence: '.implode(', ', $this->sources()).'. Pass --connection=<a '
                .$this->list($servers).' connection>, or --any-driver if the SQLite suite really is the '
                .'one you are isolating.';
        }

        if (in_array($driver, $drivers, true)) {
            return null;
        }

        return "Connection [{$connection}] is a [{$driver}] connection, but this project's test "
            .'harness pins '.$this->list($drivers).' — a scratch '.$driver.' database is not one this '
            .'suite would ever open, so isolating on it would change nothing. Evidence: '
            .implode(', ', $this->sources()).'. Pass --connection=<a '.$this->list($drivers)
            .' connection>, or --any-driver if you know better than the harness.';
    }

    /**
     * Every env var that must carry the scratch database's value: what the project CONFIGURED, what
     * the harness is observed to READ, and anything named on the command line.
     *
     * The union rather than a choice between them. Configuration is a declaration and discovery is an
     * observation; where they disagree the safe move is to set both, because an env var the suite
     * ignores costs nothing and an env var the suite reads and nobody set is the whole defect.
     *
     * @param  list<string>  $extra
     * @return list<string>
     */
    public function targetEnvVars(array $extra = []): array
    {
        return array_values(array_unique(array_merge(
            array_map('strval', (array) $this->config->get('beam.dev.env', ['DB_DATABASE'])),
            $this->envVars(),
            $extra,
        )));
    }

    /**
     * Env vars the harness reads that configuration never declared — the ones worth saying out loud,
     * because they are the ones a hand-rolled isolation attempt would have missed.
     *
     * @return list<string>
     */
    public function undeclaredEnvVars(): array
    {
        $declared = array_map('strval', (array) $this->config->get('beam.dev.env', ['DB_DATABASE']));

        return array_values(array_diff($this->envVars(), $declared));
    }

    /**
     * Every `<env>` / `<server>` pin in the project's phpunit config, by name.
     *
     * A pin is not the same kind of fact as anything else this class reads, and the difference is
     * exact rather than heuristic. `PhpHandler::handleEnvVariables()` in PHPUnit is one line:
     *
     *     if ($force || getenv($name) === false) { putenv("{$name}={$value}"); }
     *
     * So **an unforced pin cannot defeat a shell override, and a forced one always does.** Measured
     * on PHPUnit 12.5.33 rather than read: with both vars exported in the shell, an unforced pin
     * yielded `FROM_SHELL` and `force="true"` yielded `PINNED_IN_XML`.
     *
     * That distinction is worth the parser. Treating every pin as fatal would refuse at `~/Herd/audiostud`,
     * whose `phpunit.xml` pins `DB_DATABASE=audiostud_testing` without `force` — and where a shell
     * override demonstrably does reach the suite.
     *
     * @return array<string, array{value: string, force: bool, file: string}>
     */
    public function pins(): array
    {
        if ($this->pins !== null) {
            return $this->pins;
        }

        $pins = [];

        foreach ($this->roots as $root) {
            foreach (['phpunit.xml', 'phpunit.xml.dist'] as $name) {
                $file = rtrim($root, '/').'/'.$name;

                if (! is_file($file)) {
                    continue;
                }

                $xml = @simplexml_load_file($file);

                if ($xml === false) {
                    continue;
                }

                foreach (['env', 'server'] as $element) {
                    foreach ($xml->xpath('//php/'.$element) ?: [] as $node) {
                        $key = (string) ($node['name'] ?? '');

                        if ($key === '' || isset($pins[$key])) {
                            continue;
                        }

                        $pins[$key] = [
                            'value' => (string) ($node['value'] ?? ''),
                            'force' => filter_var((string) ($node['force'] ?? 'false'), FILTER_VALIDATE_BOOL),
                            'file' => $this->relative($file),
                        ];
                    }
                }
            }
        }

        return $this->pins = $pins;
    }

    /**
     * The suite already gives every run its own database, so there is nothing for this tool to make.
     *
     * The third outcome, alongside "isolated" and "refused". A suite pinned to in-memory SQLite is
     * isolated by construction — the database lives and dies inside one process and no concurrent run
     * can reach it. Creating a scratch database for it would be a database nobody opens, which is the
     * same class of uselessness this command exists to stop, just with a friendlier face on it.
     *
     * @return string|null the reason there is nothing to do, or null when there is
     */
    public function alreadyIsolated(): ?string
    {
        foreach ($this->pins() as $name => $pin) {
            if (! str_ends_with($name, 'DB_DATABASE') || ! str_contains($pin['value'], ':memory:')) {
                continue;
            }

            return "{$pin['file']} pins {$name}={$pin['value']} — an in-memory database, which lives and "
                .'dies inside one process. This suite is already isolated by construction: no concurrent '
                .'run can reach its database, and a scratch database created here would be one nothing '
                .'opens. Nothing to do.';
        }

        return null;
    }

    /**
     * Refuse when the phpunit config will overwrite the very variables we are about to print.
     *
     * The narrow, provable case: `force="true"`. Printing an env line that PHPUnit is guaranteed to
     * discard is the worst outcome this command has — the caller runs it, the suite lands on the
     * shared database, and nothing anywhere reports a problem.
     *
     * @param  list<string>  $vars
     */
    public function refusePinnedEnv(array $vars, string $value): ?string
    {
        foreach ($vars as $var) {
            $pin = $this->pins()[$var] ?? null;

            if ($pin === null || ! $pin['force'] || $pin['value'] === $value) {
                continue;
            }

            return "{$pin['file']} pins {$var}={$pin['value']} with force=\"true\", and PHPUnit's "
                .'force branch overwrites the shell value unconditionally. Anything printed here for '
                ."[{$var}] would be discarded before the first test ran, and the suite would land on "
                .'the shared database with nothing reporting it. Repair the pin rather than working '
                .'around it: drop force="true" so a shell override wins, point the pin at the scratch '
                .'database, or run the suite with --configuration=<a copy without the pin>.';
        }

        return null;
    }

    /**
     * Pins that do NOT stand in the way — reported so the caller knows they were looked at.
     *
     * Without this, a caller who opens phpunit.xml, sees their variable hardcoded, and concludes the
     * override cannot possibly work is reasoning correctly from incomplete information. Naming the
     * unforced pin and saying it loses is cheaper than the hour it costs them to find out.
     *
     * @param  list<string>  $vars
     * @return list<string>
     */
    public function overriddenPins(array $vars): array
    {
        $found = [];

        foreach ($vars as $var) {
            $pin = $this->pins()[$var] ?? null;

            if ($pin !== null && ! $pin['force']) {
                $found[] = "{$var}={$pin['value']} in {$pin['file']}";
            }
        }

        return $found;
    }

    /**
     * The directories the scan reads, in order — printed by any error that resolved a path through
     * them, because "not found" without "looked here" is the least actionable error a tool can emit.
     *
     * @return list<string>
     */
    public function roots(): array
    {
        return $this->roots;
    }

    /**
     * Resolve a project-relative path against the roots, not against `base_path()`.
     *
     * Same testbench trap as the scan, one surface over: `--init=database/init/extensions.sql` was
     * resolved with `base_path()`, which under `vendor/bin/testbench` is the skeleton inside
     * `vendor/orchestra/testbench-core/laravel`. The SQL a package's own repo ships is never there,
     * so the provisioning step could not find a file sitting in plain view of the caller's shell.
     *
     * Absolute paths pass through. A relative path that matches nothing falls back to `base_path()`
     * so the error names a path the caller can reason about rather than silently resolving elsewhere.
     */
    public function resolveProjectPath(string $path, string $fallback): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        foreach ($this->roots as $root) {
            $candidate = rtrim($root, '/').'/'.$path;

            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return rtrim($fallback, '/').'/'.$path;
    }

    /**
     * @return array{env: list<string>, drivers: list<string>, sources: list<string>}
     */
    private function scan(): array
    {
        if ($this->scan !== null) {
            return $this->scan;
        }

        $env = [];
        $drivers = [];
        $sources = [];

        foreach ($this->harnessFiles() as $file) {
            $contents = (string) @file_get_contents($file);

            if ($contents === '') {
                continue;
            }

            $found = false;

            foreach ([self::ENV_CALL, self::ENV_DECLARATION] as $pattern) {
                if (preg_match_all($pattern, $contents, $matches)) {
                    $env = array_merge($env, $matches[1]);
                    $found = true;
                }
            }

            if (preg_match_all(self::DRIVER, $contents, $matches)) {
                $drivers = array_merge($drivers, $matches[1]);
                $found = true;
            }

            if ($found) {
                $sources[] = $this->relative($file);
            }
        }

        sort($env);
        sort($drivers);
        sort($sources);

        return $this->scan = [
            'env' => array_values(array_unique($env)),
            'drivers' => array_values(array_unique($drivers)),
            'sources' => array_values(array_unique($sources)),
        ];
    }

    /**
     * The files a Laravel or testbench project keeps its harness wiring in.
     *
     * Bounded on purpose. `tests/` is walked, but only PHP files and only to a depth that reaches the
     * usual `tests/Feature/…` — a full recursive read of an estate-sized repo is not something a
     * command that should answer in under a second can afford, and the harness is never buried deeper
     * than its own test files.
     *
     * @return list<string>
     */
    private function harnessFiles(): array
    {
        $files = [];

        foreach ($this->roots as $root) {
            $root = rtrim($root, '/');

            foreach (['phpunit.xml', 'phpunit.xml.dist', 'testbench.yaml', '.env.testing'] as $name) {
                if (is_file($root.'/'.$name)) {
                    $files[] = $root.'/'.$name;
                }
            }

            foreach (['/tests/*.php', '/tests/*/*.php', '/tests/*/*/*.php'] as $glob) {
                foreach (glob($root.$glob) ?: [] as $file) {
                    $files[] = $file;
                }
            }
        }

        return array_values(array_unique($files));
    }

    private function relative(string $file): string
    {
        foreach ($this->roots as $root) {
            $root = rtrim($root, '/').'/';

            if (str_starts_with($file, $root)) {
                return substr($file, strlen($root));
            }
        }

        return $file;
    }

    /**
     * @param  list<string>  $items
     */
    private function list(array $items): string
    {
        return count($items) === 1 ? $items[0] : implode('/', $items);
    }
}
