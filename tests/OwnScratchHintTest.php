<?php

namespace Splicewire\Beam\Dev\Tests;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;
use Splicewire\Beam\Dev\Databases\ServerConnection;

/** Public allocation output only: server operations are forbidden, not PostgreSQL lifecycle proof. */
class OwnScratchHintTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.pgsql_hint', [
            'driver' => 'pgsql', 'database' => 'original_database', 'prefix' => '',
        ]);
        $app['config']->set('database.connections.mysql_hint', [
            'driver' => 'mysql', 'database' => 'original_database', 'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Driver/name inspection remains real configuration. Any attempt to open a server fails
        // locally, so this output fixture cannot become an accidental live PostgreSQL test.
        $server = new class($this->app['db'], $this->app['config']) extends ServerConnection
        {
            public function for(string $connection): Connection
            {
                throw new LogicException('Output-only fixtures must not open a server connection.');
            }
        };
        $this->app->instance(ServerConnection::class, $server);
        $this->app->instance(ScratchDatabases::class, new class($server, $this->app['config']) extends ScratchDatabases
        {
            public function create(string $name, string $connection): bool
            {
                return true; // Simulated allocation for the public output seam only.
            }

            public function assertReachable(string $name, string $connection): void
            {
                // No database is created or contacted by this output fixture.
            }
        });
    }

    public function test_pgsql_allocation_prints_a_conditional_exact_name_own_scratch_alternative(): void
    {
        $name = 'test_hint_owned_abc123';
        $output = $this->allocationOutput('pgsql_hint', $name);
        $lines = array_map(trim(...), preg_split('/\R/', $output));
        $ordinary = 'php artisan splicewire:beam:dev:drop-db test_hint_owned_abc123 --connection=pgsql_hint';
        $own = $ordinary.' --own-scratch=test_hint_owned_abc123';
        $condition = 'Only if connection [pgsql_hint] is configured to use this owned scratch database [test_hint_owned_abc123]';

        $this->assertContains($ordinary, $lines);
        $this->assertContains($own, $lines);
        $this->assertStringContainsString($condition, $output);
        $this->assertLessThan(strpos($output, $own), strpos($output, $condition));
        $this->assertDoesNotMatchRegularExpression('/--own-scratch=test_(?:\s|$)/', $output);
        $this->assertStringNotContainsString('--force', $output);
    }

    public function test_a_valid_configured_prefix_still_prints_the_exact_database_name_as_prefix(): void
    {
        config()->set('beam.dev.prefix', 'test_ci_');
        $output = $this->allocationOutput('pgsql_hint', 'test_ci_owned_abc123');
        $lines = array_map(trim(...), preg_split('/\R/', $output));

        $this->assertContains('php artisan splicewire:beam:dev:drop-db test_ci_owned_abc123 --connection=pgsql_hint', $lines);
        $this->assertContains('php artisan splicewire:beam:dev:drop-db test_ci_owned_abc123 --connection=pgsql_hint --own-scratch=test_ci_owned_abc123', $lines);
        $this->assertDoesNotMatchRegularExpression('/--own-scratch=test_ci_(?:\s|$)/', $output);
    }

    public function test_own_scratch_alternative_names_the_alias_even_when_it_is_currently_default(): void
    {
        config()->set('database.default', 'pgsql_hint');
        $output = $this->allocationOutput('pgsql_hint', 'test_default_owned');
        $lines = array_map(trim(...), preg_split('/\R/', $output));

        $this->assertContains('php artisan splicewire:beam:dev:drop-db test_default_owned', $lines);
        $this->assertContains('php artisan splicewire:beam:dev:drop-db test_default_owned --connection=pgsql_hint --own-scratch=test_default_owned', $lines);
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function ineligibleHints(): array
    {
        return [
            'sqlite' => ['scratch', 'test_', 'test_sqlite_owned', 'php artisan splicewire:beam:dev:drop-db test_sqlite_owned'],
            'mysql' => ['mysql_hint', 'test_', 'test_mysql_owned', 'php artisan splicewire:beam:dev:drop-db test_mysql_owned --connection=mysql_hint'],
            'bare test namespace' => ['pgsql_hint', 'test_', 'test_', 'php artisan splicewire:beam:dev:drop-db test_ --connection=pgsql_hint'],
            'not longer than configured prefix' => ['pgsql_hint', 'test_ci_', 'test_ci_', 'php artisan splicewire:beam:dev:drop-db test_ci_ --connection=pgsql_hint'],
            'outside test namespace' => ['pgsql_hint', 'tenant_', 'tenant_owned', 'php artisan splicewire:beam:dev:drop-db tenant_owned --connection=pgsql_hint'],
            'uppercase exact name' => ['pgsql_hint', 'test_', 'test_OWNED', 'php artisan splicewire:beam:dev:drop-db test_OWNED --connection=pgsql_hint'],
        ];
    }

    #[DataProvider('ineligibleHints')]
    public function test_ineligible_cases_keep_only_the_ordinary_hint(string $connection, string $prefix, string $name, string $ordinary): void
    {
        config()->set('beam.dev.prefix', $prefix);
        $output = $this->allocationOutput($connection, $name);
        $lines = array_map(trim(...), preg_split('/\R/', $output));

        $this->assertContains($ordinary, $lines);
        $this->assertStringNotContainsString('--own-scratch=', $output);
        $this->assertStringNotContainsString('--force', $output);
    }

    private function allocationOutput(string $connection, string $name): string
    {
        $status = Artisan::call('splicewire:beam:dev:isolated-test-db', [
            '--connection' => $connection, '--name' => $name, '--no-init' => true,
        ]);
        $output = Artisan::output();
        $this->assertSame(0, $status, $output);

        return $output;
    }
}
