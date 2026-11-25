<?php

namespace Splicewire\Beam\Dev\Tests;

use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Arr;
use RuntimeException;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;

/**
 * An exact-name drop asks the server for exactly that name (ticket owner-review-20261112 74).
 *
 * `_` is a LIKE wildcard, and almost every scratch name carries one: answered by LIKE, `test_a_b` "exists" whenever
 * `test_aXb` does, so drop-db printed "Dropped" for a database that was never there (its DROP ... IF EXISTS did
 * nothing). A miss also says which other configured connection's server does hold the name, because the 2026-11-18
 * "does not exist" for two live orphans most likely came from asking the wrong connection (inferred, not reproduced).
 */
class ExactNameDropTest extends TestCase
{
    /** @var array<string, list<string>> server databases per connection name */
    private array $servers = [];

    /** @var list<string> every statement a fixture server was sent */
    private array $statements = [];

    /**
     * Fixture pgsql connections whose servers hold $databases. Only `scratch` (the suite's sqlite default) is kept
     * from the testbench's own list, so a miss probes these fixtures and never a real local server.
     *
     * @param  array<string, array{host: string, databases: list<string>}>  $connections
     */
    private function servers(array $connections): void
    {
        config()->set('database.connections', Arr::only(config('database.connections'), ['scratch']));

        foreach ($connections as $name => $connection) {
            config()->set("database.connections.{$name}", [
                'driver' => 'pgsql',
                'host' => $connection['host'],
                'port' => 55432,
                'database' => 'fixture_app',
            ]);
            $this->servers["beam-dev-server:{$name}"] = $connection['databases'];
        }

        $this->app['db']->extend('pgsql', function (array $config, string $name) {
            if (! array_key_exists($name, $this->servers) || $config['host'] === 'down.invalid') {
                throw new RuntimeException("The fixture server for [{$name}] is unreachable.");
            }

            return $this->server($this->servers[$name]);
        });
    }

    /**
     * A Postgres server holding $databases that answers `=` and LIKE (`_` one character, `%` any run, `\` escapes
     * the next) as Postgres does, and records every statement instead of running it.
     *
     * @param  list<string>  $databases
     */
    private function server(array $databases): PostgresConnection
    {
        $statements = &$this->statements;

        return new class($databases, $statements) extends PostgresConnection
        {
            /** @param  list<string>  $databases */
            public function __construct(private array $databases, private array &$sent)
            {
                parent::__construct(fn () => throw new RuntimeException('no server in this test'), 'postgres');
            }

            public function selectOne($query, $bindings = [], $useReadPdo = true)
            {
                return (object) ['total' => 0];
            }

            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
            {
                if (! str_contains($query, 'pg_database')) {
                    return [];
                }
                if ($bindings === []) {
                    return array_map(fn ($db) => (object) ['name' => $db], $this->databases);
                }
                $like = str_contains($query, ' like ?');
                $pattern = '/^'.preg_replace_callback('/\\\\(.)|(_)|(%)|(.)/s', fn ($m) => match (true) {
                    ($m[1] ?? '') !== '' => preg_quote($m[1], '/'),
                    ($m[2] ?? '') !== '' => '.',
                    ($m[3] ?? '') !== '' => '.*',
                    default => preg_quote($m[4], '/'),
                }, $bindings[0]).'$/s';
                $hits = array_filter($this->databases, fn ($db) => $like ? preg_match($pattern, $db) === 1 : $db === $bindings[0]);

                return array_map(fn ($db) => (object) ['name' => $db, 'found' => 1], array_values($hits));
            }

            public function statement($query, $bindings = [])
            {
                $this->sent[] = $query;

                return true;
            }
        };
    }

    public function test_an_exact_name_is_not_answered_by_a_sibling_its_underscores_would_match(): void
    {
        $this->servers(['pg_fixture' => ['host' => 'fixture.invalid', 'databases' => ['test_aXb']]]);

        $databases = $this->app->make(ScratchDatabases::class);
        $this->assertFalse($databases->exists('test_a_b', 'pg_fixture'));
        $this->assertTrue($databases->exists('test_aXb', 'pg_fixture'));

        $this->artisan('splicewire:beam:dev:drop-db', [
            'names' => ['test_a_b'],
            '--connection' => 'pg_fixture',
            '--keep-workers' => true,
        ])->expectsOutputToContain('Skipped [test_a_b] — it does not exist on connection [pg_fixture] (pgsql).')
            ->doesntExpectOutputToContain('Dropped')
            ->assertSuccessful();

        $this->assertSame([], $this->statements, 'No DROP may be sent for a database that is not there.');
    }

    public function test_a_pattern_still_matches_with_like(): void
    {
        $this->servers(['pg_fixture' => ['host' => 'fixture.invalid', 'databases' => ['test_ci_1', 'test_ci_2', 'test_other']]]);

        $this->assertSame(['test_ci_1', 'test_ci_2'], $this->app->make(ScratchDatabases::class)->names('pg_fixture', 'test\_ci\_%'));
    }

    public function test_a_miss_names_the_other_connection_whose_server_holds_it(): void
    {
        // The 11-18 shape: the default (the suite's sqlite) is asked for a database that lives on a pgsql server. A
        // second connection to that same server is probed once, not twice; an unreachable one is passed over.
        $this->servers([
            'pg_main' => ['host' => 'pg.invalid', 'databases' => ['test_external']],
            'pg_twin' => ['host' => 'pg.invalid', 'databases' => ['test_external']],
            'pg_down' => ['host' => 'down.invalid', 'databases' => []],
        ]);

        $this->artisan('splicewire:beam:dev:drop-db', ['names' => ['test_external'], '--keep-workers' => true])
            ->expectsOutputToContain('it does not exist on connection [scratch] (sqlite). It does exist on connection [pg_main] (pgsql): pass --connection=pg_main.')
            ->doesntExpectOutputToContain('pg_twin')
            ->doesntExpectOutputToContain('pg_down')
            ->assertSuccessful();

        $this->assertSame([], $this->statements);
    }

    public function test_a_miss_found_nowhere_else_says_only_that(): void
    {
        $this->servers(['pg_main' => ['host' => 'pg.invalid', 'databases' => ['test_other']]]);

        $this->artisan('splicewire:beam:dev:drop-db', ['names' => ['test_external'], '--keep-workers' => true])
            ->expectsOutputToContain('Skipped [test_external] — it does not exist on connection [scratch] (sqlite).')
            ->doesntExpectOutputToContain('It does exist')
            ->assertSuccessful();
    }
}
