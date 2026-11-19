<?php

namespace Splicewire\Beam\Dev\Tests;

use Illuminate\Database\Connection;
use RuntimeException;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;
use Splicewire\Beam\Dev\Databases\ServerConnection;

class ExclusiveAllocationTest extends TestCase
{
    public function test_server_allocation_uses_exclusive_ddl_and_propagates_a_collision_without_reuse(): void
    {
        foreach (['pgsql' => 'CREATE DATABASE "test_exclusive"', 'mysql' => 'CREATE DATABASE `test_exclusive`'] as $driver => $expected) {
            // The server branch's SQL is inspected without opening a PostgreSQL/MySQL session.
            $target = new class(null) extends Connection
            {
                public array $statements = [];

                public bool $succeeds = true;

                public function statement($query, $bindings = [])
                {
                    if ($this->statements !== []) {
                        throw new RuntimeException('fixture server collision');
                    }

                    $this->statements[] = $query;

                    return $this->succeeds;
                }
            };
            $server = new class($this->app['db'], config()) extends ServerConnection
            {
                public Connection $target;

                public string $driver;

                public function for(string $connection): Connection
                {
                    return $this->target;
                }

                public function driverFor(string $connection): ?string
                {
                    return $this->driver;
                }
            };
            $server->target = $target;
            $server->driver = $driver;
            $databases = new ScratchDatabases($server, config());
            $databases->createExclusive('test_exclusive', 'scratch');
            $this->assertSame([$expected], $target->statements);

            try {
                $databases->createExclusive('test_exclusive', 'scratch');
                $this->fail('An existing server database must not be silently reused.');
            } catch (RuntimeException $e) {
                $this->assertSame('fixture server collision', $e->getMessage());
            }

            $this->assertSame([$expected], $target->statements);
            $target->statements = [];
            $target->succeeds = false;

            try {
                $databases->createExclusive('test_exclusive', 'scratch');
                $this->fail('A silent server failure must not acquire ownership.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('no ownership was acquired', $e->getMessage());
            }
        }
    }
}
