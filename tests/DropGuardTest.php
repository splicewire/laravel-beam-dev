<?php

namespace Splicewire\Beam\Dev\Tests;

use Splicewire\Beam\Dev\Databases\DropGuard;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;

/**
 * The guard is the part of this package that must never regress: everything else creates things,
 * this is the only thing that destroys them. Each rule gets its own test, including the two that
 * `--force` must NOT be able to override.
 */
class DropGuardTest extends TestCase
{
    private function guard(): DropGuard
    {
        return $this->app->make(DropGuard::class);
    }

    public function test_it_allows_a_prefixed_scratch_database(): void
    {
        $this->assertNull($this->guard()->refuse('test_abc123', 'scratch', 'test_', force: false));
    }

    public function test_it_refuses_a_name_outside_the_scratch_prefix(): void
    {
        $reason = $this->guard()->refuse('production_analytics', 'scratch', 'test_', force: false);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('does not start with the scratch prefix', $reason);
    }

    public function test_force_allows_a_name_outside_the_prefix(): void
    {
        $this->assertNull($this->guard()->refuse('legacy_scratch', 'scratch', 'test_', force: true));
    }

    /**
     * The database the app is configured to use is the one thing on the server that is certainly not
     * scratch — and it would still be there after being dropped from under the running app, which is
     * the worst kind of "worked, then everything broke".
     */
    public function test_it_refuses_the_connections_own_database(): void
    {
        $own = basename((string) config('database.connections.scratch.database'), '.sqlite');

        $reason = $this->guard()->refuse($own, 'scratch', '', force: true);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('configured to use', $reason);
    }

    /**
     * Not overridable, not promptable, not behind a flag. A switch that can delete a production
     * database is a switch someone eventually flips in the wrong shell.
     */
    public function test_it_refuses_everything_in_production_even_with_force(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $reason = $this->guard()->refuse('test_abc123', 'scratch', 'test_', force: true);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('production', $reason);
        $this->assertStringContainsString('not overridable', strtolower($reason));
    }

    /**
     * The sibling-session rule. This is the failure that cost real hours: dropping a database another
     * suite is mid-run on produces vanished rows and phantom collisions in THAT run, which read
     * exactly like genuine regressions in whatever it was testing.
     */
    public function test_it_refuses_a_database_with_live_sessions_even_with_force(): void
    {
        $this->app->instance(ScratchDatabases::class, new class($this->app) extends ScratchDatabases
        {
            public function __construct(private $app) {}

            public function activeConnections(string $name, string $connection): int
            {
                return 3;
            }
        });

        $reason = $this->app->make(DropGuard::class)
            ->refuse('test_busy', 'scratch', 'test_', force: true);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('3 active connection', $reason);
        $this->assertStringContainsString('--force does not override', $reason);
    }
}
