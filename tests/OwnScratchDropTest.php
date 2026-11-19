<?php

namespace Splicewire\Beam\Dev\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Splicewire\Beam\Dev\Databases\DropGuard;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;
use Splicewire\Beam\Dev\Databases\ServerConnection;

/**
 * `drop-db --own-scratch=<prefix>`: the one exception to "never the connection's own database".
 *
 * A host pointed AT a scratch database (a whiffletree space's `test_space_*` database, named by its
 * own `.env`) has to be able to drop it from its own app without the caller rewriting the child's
 * DB_DATABASE. The exception is narrow, and each edge is pinned here: pgsql only, named databases
 * only, a caller prefix that can only narrow `test_` and the scratch prefix, and the production and
 * live-session rules untouched. The server is never reached: the pgsql connection is configuration,
 * and the session count and the DROP are stubbed.
 */
class OwnScratchDropTest extends TestCase
{
    private const OWN = 'test_space_s1_acme_app_0123abcd';

    /** @var list<string> */
    private array $dropped = [];

    /** @var list<string> */
    private array $released = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.space', [
            'driver' => 'pgsql', 'host' => '/tmp', 'port' => 5432, 'database' => self::OWN,
            'username' => 'root', 'password' => '', 'prefix' => '',
        ]);
        $app['config']->set('database.connections.mysqlspace', [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => self::OWN,
            'username' => 'root', 'password' => '', 'prefix' => '',
        ]);
    }

    /**
     * A ScratchDatabases that touches no server: $workers as every worker listing, $active sessions on
     * any database, except that the process's OWN handle (one more session) counts until it is released,
     * and a recorded DROP.
     *
     * @param  list<string>  $workers
     */
    private function stubDatabases(int $active = 0, array $workers = [], bool $ownHandle = false): void
    {
        $test = $this;

        $this->app->instance(ScratchDatabases::class, new class($test, $active, $workers, $ownHandle) extends ScratchDatabases
        {
            public function __construct(private $test, private int $active, private array $workers, private bool $ownHandle) {}

            public function names(string $connection, ?string $like = null): array
            {
                return $this->workers;
            }

            public function activeConnections(string $name, string $connection): int
            {
                return $this->active + ($this->ownHandle && ! in_array($name, $this->test->released(), true) ? 1 : 0);
            }

            public function drop(string $name, string $connection): bool
            {
                $this->test->recordDrop($name);

                return true;
            }

            public function releaseLocalHandles(string $name, string $connection): void
            {
                $this->test->recordRelease($name);
            }
        });
    }

    /** @return list<string> */
    public function released(): array
    {
        return $this->released;
    }

    public function recordDrop(string $name): void
    {
        $this->dropped[] = $name;
    }

    public function recordRelease(string $name): void
    {
        $this->released[] = $name;
    }

    private function guard(): DropGuard
    {
        return $this->app->make(DropGuard::class);
    }

    public function test_the_own_scratch_database_is_refused_without_the_option(): void
    {
        $this->stubDatabases();

        $reason = $this->guard()->refuse(self::OWN, 'space', 'test_', force: false);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('configured to use', $reason);
        $this->assertStringContainsString('--own-scratch', $reason);
    }

    public function test_the_own_scratch_database_is_allowed_with_the_option(): void
    {
        $this->stubDatabases();

        $this->assertNull($this->guard()->refuse(self::OWN, 'space', 'test_', force: false, ownScratch: 'test_space_', named: true));
    }

    public function test_the_own_scratch_database_is_refused_when_it_was_not_named(): void
    {
        $this->stubDatabases();

        $this->assertStringContainsString('named explicitly', (string) $this->guard()->refuse(self::OWN, 'space', 'test_', false, 'test_space_'));
    }

    public function test_an_own_database_outside_the_callers_prefix_is_refused(): void
    {
        $this->stubDatabases();

        $reason = $this->guard()->refuse(self::OWN, 'space', 'test_', force: true, ownScratch: 'test_ci_', named: true);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('does not start with the --own-scratch prefix [test_ci_]', $reason);
    }

    /**
     * The caller cannot widen the exception: a non-test own database is refused whatever prefix it
     * passes and with --force, because every prefix must extend `test_`.
     */
    public function test_a_non_test_own_database_is_refused_even_with_the_option_and_force(): void
    {
        $this->stubDatabases();
        config()->set('database.connections.space.database', 'app_main');

        foreach (['test_space_', 'app_', 'app_main'] as $prefix) {
            $reason = $this->guard()->refuse('app_main', 'space', 'test_', force: true, ownScratch: $prefix, named: true);

            $this->assertNotNull($reason, "prefix {$prefix}");
        }

        $this->assertStringContainsString('does not start with the --own-scratch prefix', (string) $this->guard()->refuse('app_main', 'space', 'test_', true, 'test_space_', true));
        $this->assertStringContainsString('must extend [test_]', (string) $this->guard()->refuse('app_main', 'space', 'test_', true, 'app_', true));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function widePrefixes(): array
    {
        return [
            'the bare namespace' => ['test_', 'must extend [test_] and be longer'],
            'a LIKE wildcard' => ['test_space%', 'must be 1-63 characters'],
            'a single-character wildcard read as LIKE' => ['test_spac?', 'must be 1-63 characters'],
            'uppercase' => ['TEST_SPACE_', 'must be 1-63 characters'],
            'empty' => ['', 'must be 1-63 characters'],
            'a non-test name' => ['prod_', 'must extend [test_]'],
        ];
    }

    #[DataProvider('widePrefixes')]
    public function test_a_prefix_that_could_widen_the_exception_is_refused(string $prefix, string $why): void
    {
        $this->stubDatabases();

        $this->assertStringContainsString($why, (string) $this->guard()->refuseOwnScratch($prefix, 'space', 'test_'));
        $this->assertStringContainsString($why, (string) $this->guard()->refuse(self::OWN, 'space', 'test_', false, $prefix, true));
    }

    public function test_the_prefix_must_also_extend_a_configured_scratch_prefix(): void
    {
        $this->stubDatabases();

        $this->assertStringContainsString('must extend [test_space_s2_]', (string) $this->guard()->refuseOwnScratch('test_space_', 'space', 'test_space_s2_'));
        $this->assertNull($this->guard()->refuseOwnScratch('test_space_s1_', 'space', 'test_space_'));
    }

    public function test_live_sessions_on_the_own_database_still_refuse(): void
    {
        $this->stubDatabases(active: 2);

        $reason = $this->guard()->refuse(self::OWN, 'space', 'test_', force: true, ownScratch: 'test_space_', named: true);

        $this->assertStringContainsString('2 active connection', (string) $reason);
    }

    public function test_production_still_refuses(): void
    {
        $this->stubDatabases();
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertStringContainsString('production', (string) $this->guard()->refuse(self::OWN, 'space', 'test_', true, 'test_space_', true));
    }

    public function test_mysql_and_sqlite_refuse_the_option_clearly(): void
    {
        $this->stubDatabases();

        $this->assertStringContainsString('pgsql only; connection [mysqlspace] is [mysql]', (string) $this->guard()->refuse(self::OWN, 'mysqlspace', 'test_', false, 'test_space_', true));

        config()->set('database.connections.scratch.database', $this->scratchDir().'/'.self::OWN.'.sqlite');
        $this->assertStringContainsString('pgsql only; connection [scratch] is [sqlite]', (string) $this->guard()->refuse(self::OWN, 'scratch', 'test_', false, 'test_space_', true));
    }

    public function test_other_databases_are_judged_exactly_as_before(): void
    {
        $this->stubDatabases();

        $this->assertNull($this->guard()->refuse('test_space_other', 'space', 'test_', false, 'test_space_'));
        $this->assertStringContainsString('scratch prefix', (string) $this->guard()->refuse('legacy', 'space', 'test_', false, 'test_space_'));
    }

    public function test_the_command_drops_its_own_scratch_database_after_releasing_its_own_handles(): void
    {
        $this->stubDatabases(ownHandle: true);

        $this->artisan('splicewire:beam:dev:drop-db', ['names' => [self::OWN], '--connection' => 'space', '--own-scratch' => 'test_space_'])
            ->expectsOutputToContain('Dropped '.self::OWN." (the connection's own database, --own-scratch=test_space_)")
            ->assertSuccessful();

        $this->assertSame([self::OWN], $this->dropped);
        $this->assertSame([self::OWN], $this->released);
    }

    public function test_the_command_refuses_its_own_database_without_the_option(): void
    {
        $this->stubDatabases();

        $this->artisan('splicewire:beam:dev:drop-db', ['names' => [self::OWN], '--connection' => 'space'])
            ->expectsOutputToContain('configured to use')
            ->assertFailed();

        $this->assertSame([], $this->dropped);
        $this->assertSame([], $this->released);
    }

    public function test_the_command_refuses_the_option_on_a_sweep_and_on_sqlite_before_listing_anything(): void
    {
        $this->stubDatabases();

        $this->artisan('splicewire:beam:dev:drop-db', ['--all' => true, '--connection' => 'space', '--own-scratch' => 'test_space_'])
            ->expectsOutputToContain('applies to databases named explicitly')
            ->assertFailed();

        $this->artisan('splicewire:beam:dev:drop-db', ['names' => ['test_space_x'], '--own-scratch' => 'test_space_'])
            ->expectsOutputToContain('pgsql only')
            ->assertFailed();

        $this->artisan('splicewire:beam:dev:drop-db', ['names' => [self::OWN], '--connection' => 'space', '--own-scratch' => 'test_'])
            ->expectsOutputToContain('must extend [test_] and be longer')
            ->assertFailed();

        $this->assertSame([], $this->dropped);
    }

    public function test_a_named_non_own_database_is_not_released_and_an_own_worker_is_not_named(): void
    {
        config()->set('database.connections.space.database', 'test_space_p_test_1');
        $this->stubDatabases(workers: ['test_space_p_test_1']);

        $this->artisan('splicewire:beam:dev:drop-db', ['names' => ['test_space_p'], '--connection' => 'space', '--own-scratch' => 'test_space_p'])
            ->expectsOutputToContain('named explicitly')
            ->assertSuccessful();

        $this->assertSame(['test_space_p'], $this->dropped);
        $this->assertSame([], $this->released);
    }

    public function test_a_valueless_option_is_refused_not_ignored(): void
    {
        $this->stubDatabases();

        $this->artisan('splicewire:beam:dev:drop-db', ['names' => [self::OWN], '--connection' => 'space', '--own-scratch' => null])
            ->expectsOutputToContain('must be 1-63 characters')
            ->assertFailed();

        $this->assertSame([], $this->dropped);
    }

    public function test_a_trailing_newline_does_not_pass_the_prefix_charset(): void
    {
        $this->stubDatabases();

        $this->assertStringContainsString('must be 1-63 characters', (string) $this->guard()->refuseOwnScratch("test_space_\n", 'space', 'test_'));
    }

    public function test_release_local_handles_disconnects_only_connections_on_that_database(): void
    {
        config()->set('database.connections.other', ['driver' => 'sqlite', 'database' => $this->scratchDir().'/other.sqlite', 'prefix' => '']);
        touch($this->scratchDir().'/other.sqlite');
        touch((string) config('database.connections.scratch.database'));

        app('db')->connection('scratch')->select('select 1');
        app('db')->connection('other')->select('select 1');

        (new ScratchDatabases(app(ServerConnection::class), config()))
            ->releaseLocalHandles((string) config('database.connections.scratch.database'), 'scratch');

        $open = array_keys(app('db')->getConnections());
        $this->assertNotContains('scratch', $open);
        $this->assertContains('other', $open);
    }
}
