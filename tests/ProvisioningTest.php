<?php

namespace Splicewire\Beam\Dev\Tests;

use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Dev\Console\IsolatedTestDbCommand;
use Splicewire\Beam\Dev\Databases\ProvisioningSql;
use Splicewire\Beam\Dev\Databases\SuiteHarness;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * The default that stopped a bare scratch database from manufacturing 465 false failures.
 *
 * Measured 2026-08-30: `splicewire/tower`'s suite against a bare database created by this command
 * read `Tests: 465 failed, 412 passed`, every failure `type "vector" does not exist`. The database
 * was correct, reachable, and useless, and the command's own line saying it was bare did not stop
 * two separate sessions reading the red as a regression.
 *
 * These tests hold the SHAPE of the fix rather than its effect — the effect needs a Postgres server
 * and this suite is SQLite by construction (see {@see TestCase::defineEnvironment}). What can be
 * asserted without one: that a project which said nothing gets the packaged file, that a project
 * which said `[]` still gets nothing, that `--no-init` overrides, and that the reader which turns a
 * failed statement into "vector, here is what to install" reads what it claims to.
 */
class ProvisioningTest extends TestCase
{
    private function packagedFile(): string
    {
        return dirname(__DIR__).'/database/init/extensions.sql';
    }

    /**
     * The default is a file this package ships, so its absence is a broken release rather than a
     * misconfiguration — and the command resolves it off `__DIR__`, which nothing else would catch.
     */
    public function test_the_packaged_provisioning_file_exists_and_creates_the_extension_that_broke_tower(): void
    {
        $this->assertFileExists($this->packagedFile());

        $sql = (string) file_get_contents($this->packagedFile());
        $extensions = array_values(array_filter(array_map(
            ProvisioningSql::extensionIn(...),
            ProvisioningSql::statements($sql),
        )));

        $this->assertSame(['uuid-ossp', 'citext', 'pg_trgm', 'fuzzystrmatch', 'vector'], $extensions);
    }

    /**
     * `pgcrypto` is deliberately absent, and this test is the record of why: the only thing this
     * estate wants from it is `gen_random_uuid()`, which is core PostgreSQL from 13 onward. Verified
     * on a bare database with zero extensions on PostgreSQL 17 (Laravel Herd), 2026-08-30. Adding it
     * would be one more extension a server can fail to have, for nothing.
     */
    public function test_pgcrypto_is_not_provisioned(): void
    {
        $this->assertStringNotContainsString(
            'CREATE EXTENSION IF NOT EXISTS pgcrypto',
            (string) file_get_contents($this->packagedFile()),
        );
    }

    /**
     * A project that said nothing gets the packaged file. `null` is the config default and it is not
     * `[]` — the whole repair lives in that distinction.
     */
    public function test_a_project_that_declared_nothing_takes_the_packaged_file(): void
    {
        config()->set('beam.dev.init', null);
        config()->set('database.connections.scratch.driver', 'pgsql');

        // Resolution happens before any connection is made, so the file question is answerable even
        // though this harness has no Postgres to run it against: a pgsql driver over a SQLite server
        // fails at connect, AFTER the init list has been resolved and named in the failure path.
        $this->assertSame([$this->packagedFile()], $this->resolvedInitFiles());
    }

    /**
     * An explicit empty list still means bare. A project that wrote `[]` — including every host
     * carrying a `config/beam/dev.php` published before 2026-08-30 — said something, and reading its
     * explicit value as "I said nothing" would be the same defect one layer up.
     */
    public function test_an_explicit_empty_list_still_means_bare(): void
    {
        config()->set('beam.dev.init', []);

        $output = $this->runIsolated(['--slug' => 'empty']);

        $this->assertStringContainsString('the database is bare', $output);
    }

    public function test_no_init_skips_provisioning(): void
    {
        config()->set('beam.dev.init', null);

        $output = $this->runIsolated(['--slug' => 'noinit', '--no-init' => true]);

        $this->assertStringContainsString('the database is bare', $output);
    }

    /**
     * The packaged file is `CREATE EXTENSION`, which is Postgres and nothing else. Defaulting it onto
     * a SQLite scratch database would turn every working invocation in a SQLite project into a hard
     * error about a file the caller never named.
     */
    public function test_the_default_does_not_reach_a_sqlite_scratch_database(): void
    {
        config()->set('beam.dev.init', null);

        $output = $this->runIsolated(['--slug' => 'sqlite']);

        $this->assertStringContainsString('the database is bare', $output);
        $this->assertStringNotContainsString('Provisioned:', $output);
    }

    /**
     * The splitter has one job that a word-character pattern gets wrong: `"uuid-ossp"` is quoted
     * precisely because it is not a bare identifier, and a reader that skips it reports the one
     * extension it could not name as "not a CREATE EXTENSION statement".
     */
    public function test_the_reader_names_quoted_and_unquoted_extensions_alike(): void
    {
        $this->assertSame('uuid-ossp', ProvisioningSql::extensionIn('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"'));
        $this->assertSame('vector', ProvisioningSql::extensionIn('CREATE EXTENSION IF NOT EXISTS vector'));
        $this->assertSame('citext', ProvisioningSql::extensionIn('create extension citext'));
        $this->assertNull(ProvisioningSql::extensionIn('CREATE SCHEMA tenant_demo'));
    }

    public function test_comments_are_not_statements(): void
    {
        $sql = "-- CREATE EXTENSION IF NOT EXISTS pgcrypto;\nCREATE EXTENSION vector;\n";

        $this->assertSame(['CREATE EXTENSION vector'], ProvisioningSql::statements($sql));
    }

    /**
     * Generic advice is the same as no advice. `vector` is the extension that took tower down and it
     * is also the one that is a separate install rather than part of contrib, so it must not be
     * folded into the same sentence as `citext`.
     */
    public function test_the_hint_distinguishes_pgvector_from_contrib(): void
    {
        $this->assertStringContainsString('pgvector', ProvisioningSql::hintFor('vector'));
        $this->assertStringContainsString('contrib', ProvisioningSql::hintFor('citext'));
        $this->assertStringContainsString('contrib', ProvisioningSql::hintFor('uuid-ossp'));
        $this->assertStringNotContainsString('pgvector', ProvisioningSql::hintFor('citext'));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function runIsolated(array $arguments): string
    {
        Artisan::call('splicewire:beam:dev:isolated-test-db', $arguments);

        return Artisan::output();
    }

    /**
     * The init list the command would resolve, read back out of the command object itself.
     *
     * Reaching in with reflection rather than asserting on output, because the output for this case
     * requires a Postgres server and the question — "which file did it pick" — does not.
     *
     * @return list<string>
     */
    private function resolvedInitFiles(): array
    {
        $command = $this->app->make(IsolatedTestDbCommand::class);
        $command->setLaravel($this->app);

        $definition = $command->getDefinition();
        $input = new ArrayInput([], $definition);
        $reflection = new \ReflectionObject($command);

        $property = $reflection->getParentClass()?->getProperty('input') ?? $reflection->getProperty('input');
        $property->setValue($command, $input);

        $method = $reflection->getMethod('initFiles');
        $method->setAccessible(true);

        return $method->invoke($command, $this->app->make(SuiteHarness::class), 'pgsql')[0];
    }
}
