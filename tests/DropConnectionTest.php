<?php

namespace Splicewire\Beam\Dev\Tests;

use Illuminate\Database\PostgresConnection;
use RuntimeException;

class DropConnectionTest extends TestCase
{
    public function test_an_exact_name_reaches_the_explicit_postgres_connection(): void
    {
        config()->set('database.connections.pg_fixture', [
            'driver' => 'pgsql',
            'host' => 'fixture.invalid',
            'port' => 55432,
            'database' => 'fixture_app',
        ]);

        $server = $this->getMockBuilder(PostgresConnection::class)
            ->setConstructorArgs([
                fn () => throw new RuntimeException('This fixture cannot open a server connection.'),
                'postgres',
            ])
            ->onlyMethods(['selectOne', 'select', 'statement'])
            ->getMock();
        $server->expects($this->once())->method('selectOne')
            ->with('select count(*) as total from pg_stat_activity where datname = ? and pid <> pg_backend_pid()', ['test_external'])
            ->willReturn((object) ['total' => 0]);
        $server->expects($this->once())->method('select')
            ->with('select datname as name from pg_database where datistemplate = false and datname like ? order by name', ['test_external'])
            ->willReturn([(object) ['name' => 'test_external']]);
        $server->expects($this->once())->method('statement')->with('DROP DATABASE IF EXISTS "test_external"')->willReturn(true);

        $this->app['db']->extend('pgsql', function (array $config, string $name) use ($server) {
            $this->assertSame('beam-dev-server:pg_fixture', $name);
            $this->assertSame('fixture.invalid', $config['host']);
            $this->assertSame(55432, $config['port']);
            $this->assertSame('postgres', $config['database']);

            return $server;
        });

        $this->artisan('splicewire:beam:dev:drop-db', ['names' => ['test_external'], '--keep-workers' => true])
            ->expectsOutputToContain('it does not exist on connection [scratch] (sqlite)')
            ->assertSuccessful();
        $this->artisan('splicewire:beam:dev:drop-db', [
            'names' => ['test_external'],
            '--connection' => 'pg_fixture',
            '--keep-workers' => true,
        ])->expectsOutputToContain('Dropped test_external on connection [pg_fixture] (pgsql)')->assertSuccessful();
    }

    public function test_an_exact_name_guard_refusal_names_the_selected_connection(): void
    {
        config()->set('database.connections.pg_fixture', [
            'driver' => 'pgsql',
            'host' => 'fixture.invalid',
            'database' => 'fixture_app',
        ]);
        $this->app['db']->extend('pgsql', fn () => throw new RuntimeException('A prefix refusal cannot open a server connection.'));

        $this->artisan('splicewire:beam:dev:drop-db', [
            'names' => ['outside_prefix'],
            '--connection' => 'pg_fixture',
            '--keep-workers' => true,
        ])->expectsOutputToContain('Skipped on connection [pg_fixture] (pgsql): [outside_prefix] does not start with the scratch prefix [test_]')
            ->assertFailed();
    }
}
