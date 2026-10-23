<?php

namespace Splicewire\Beam\Dev\Tests;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;
use Splicewire\Beam\Dev\Databases\ServerConnection;

/**
 * A test run's tenant delete can leave a hyphenated database behind (`tenantseats-probe123` on Tots, 2026-10-22), and
 * drop-db could not reap it: names were held to [A-Za-z0-9_]. A drop now also accepts a hyphen after the first
 * character and wraps the name with the connection grammar's own identifier quoting. Anything that could carry a quote,
 * a space or a statement terminator is still refused before a query is built, and creation keeps the strict charset.
 *
 * The pgsql and mysql DDL is asserted by inspection: a real Postgres/MySQL connection object in `pretend()` mode records
 * the statement without a server.
 */
class QuotedDropTest extends TestCase
{
    /**
     * @return list<string> the statements drop() issued against a `$driver` server
     */
    private function dropOn(string $driver, string $name): array
    {
        $connection = $driver === 'mysql'
            ? new MySqlConnection(fn () => throw new RuntimeException('no server in this test'), '', '', [])
            : new PostgresConnection(fn () => throw new RuntimeException('no server in this test'), '', '', []);

        $server = Mockery::mock(ServerConnection::class);
        $server->shouldReceive('driverFor')->andReturn($driver);
        $server->shouldReceive('for')->andReturn($connection);

        $databases = Mockery::mock(ScratchDatabases::class, [$server, $this->app['config']])->makePartial();
        $databases->shouldReceive('exists')->andReturn(true);

        return array_column($connection->pretend(fn () => $databases->drop($name, 'remote')), 'query');
    }

    public function test_a_hyphenated_tenant_database_is_dropped_with_a_quoted_postgres_identifier(): void
    {
        $this->assertSame(['DROP DATABASE IF EXISTS "tenantseats-probe123"'], $this->dropOn('pgsql', 'tenantseats-probe123'));
    }

    public function test_a_hyphenated_tenant_database_is_dropped_with_a_backtick_quoted_mysql_identifier(): void
    {
        $this->assertSame(['DROP DATABASE IF EXISTS `tenantseats-probe123`'], $this->dropOn('mysql', 'tenantseats-probe123'));
    }

    public function test_an_ordinary_scratch_name_drops_exactly_as_before(): void
    {
        $this->assertSame(['DROP DATABASE IF EXISTS "test_abc123"'], $this->dropOn('pgsql', 'test_abc123'));
        $this->assertSame(['DROP DATABASE IF EXISTS `test_abc123`'], $this->dropOn('mysql', 'test_abc123'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unsafeNames(): array
    {
        $names = [
            'a double quote' => 'test_a"b',
            'a backtick' => 'test_a`b',
            'a single quote' => "test_a'b",
            'a space' => 'test a',
            'a semicolon' => 'test_a;drop database x',
            'a leading hyphen' => '-test_a',
            'a dot' => 'test.a',
            'a slash' => 'test/a',
            'empty' => '',
            '64 characters' => str_repeat('a', 64),
        ];

        $cases = [];
        foreach (['pgsql', 'mysql'] as $driver) {
            foreach ($names as $label => $name) {
                $cases["{$driver}: {$label}"] = [$driver, $name];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider unsafeNames
     */
    #[DataProvider('unsafeNames')]
    public function test_a_name_that_could_carry_a_quote_space_or_terminator_is_still_refused_before_any_query(string $driver, string $name): void
    {
        try {
            $this->dropOn($driver, $name);
            $this->fail("drop() accepted [{$name}] on {$driver}");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Refusing to operate on database name', $e->getMessage());
        }
    }

    public function test_sqlite_and_creation_keep_the_strict_charset(): void
    {
        $databases = $this->app->make(ScratchDatabases::class);

        try {
            $databases->drop('test-hyphen', 'scratch');
            $this->fail('a hyphenated sqlite drop was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('[A-Za-z0-9_] only', $e->getMessage());
        }

        $this->expectExceptionMessage('[A-Za-z0-9_] only');
        $databases->create('test-hyphen', 'scratch');
    }
}
