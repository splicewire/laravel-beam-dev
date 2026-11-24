<?php

namespace Splicewire\Beam\Dev\Tests;

use Illuminate\Database\Connection;
use Mockery;
use RuntimeException;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;
use Splicewire\Beam\Dev\Databases\ServerConnection;
use Splicewire\Beam\Dev\Databases\WalkDatabaseTarget;

class WalkDatabaseTest extends TestCase
{
    public function test_serving_allocates_and_initializes_the_exact_private_target(): void
    {
        $room = $this->scratchDir();
        chmod($room, 0700);
        mkdir($room.'/socket', 0700);
        mkdir($room.'/data', 0700);
        file_put_contents($room.'/vector.sql', 'CREATE EXTENSION vector;');

        $maintenance = Mockery::mock(Connection::class);
        $maintenance->shouldReceive('selectOne')->once()->andReturn((object) [
            'socket_only' => true,
            'listen_addresses' => '',
            'data_directory' => $room.'/data',
        ]);
        $server = Mockery::mock(ServerConnection::class);
        $server->shouldReceive('for')->with('beam-dev-walk')->once()->andReturnUsing(function () use ($maintenance, $room) {
            $target = config('database.connections.beam-dev-walk');
            $this->assertSame(realpath($room.'/socket'), $target['host']);
            $this->assertSame(55439, $target['port']);
            $this->assertSame('walk_user', $target['username']);
            $this->assertSame('postgres', $target['database']);

            return $maintenance;
        });
        $this->app->instance(ServerConnection::class, $server);

        $name = null;
        $databases = Mockery::mock(ScratchDatabases::class);
        $databases->shouldReceive('createExclusive')->once()->withArgs(function (string $db, string $alias) use (&$name): bool {
            $name = $db;

            return preg_match('/^test_walk_[a-f0-9]{24}$/D', $db) === 1 && $alias === 'beam-dev-walk';
        });
        $databases->shouldReceive('assertReachable')->once()->withArgs(fn (string $db, string $alias): bool => str_starts_with($db, 'test_walk_') && $alias === 'beam-dev-walk');
        $databases->shouldReceive('runSqlFiles')->once()->withArgs(fn (string $db, string $alias, array $files): bool => str_starts_with($db, 'test_walk_') && $alias === 'beam-dev-walk' && $files === [$room.'/vector.sql'])->andReturn([]);
        $this->app->instance(ScratchDatabases::class, $databases);

        $this->artisan('splicewire:beam:dev:walk-db', [
            'action' => 'create',
            '--room' => $room,
            '--socket' => $room.'/socket',
            '--port' => '55439',
            '--user' => 'walk_user',
            '--init' => [$room.'/vector.sql'],
        ])->expectsOutputToContain('Walk database ready')->assertSuccessful();

        $this->assertNotNull($name);
        $this->assertNull(config('database.connections.beam-dev-walk'));
        rmdir($room.'/socket');
        rmdir($room.'/data');
    }

    public function test_the_scoped_env_write_preserves_unrelated_entries_and_refuses_a_link(): void
    {
        $room = $this->scratchDir();
        chmod($room, 0700);
        mkdir($room.'/socket', 0700);
        mkdir($room.'/app', 0700);
        $env = $room.'/app/.env';
        file_put_contents($env, "APP_KEY=unchanged-canary\nDB_USERNAME=old\nDATABASE_URL=old-url\nDB_HOST=old\n");
        $target = new WalkDatabaseTarget($room, $room.'/socket', '55439', 'walk_user');

        try {
            $target->writeEnv($env, 'test_walk_example');
            $this->assertSame("APP_KEY=unchanged-canary\nDB_CONNECTION=pgsql\nDB_HOST=".realpath($room.'/socket')
                ."\nDB_PORT=55439\nDB_DATABASE=test_walk_example\nDB_USERNAME=walk_user\nDB_PASSWORD=\nDB_URL=\nDATABASE_URL=\n", file_get_contents($env));
            $this->assertSame(0600, fileperms($env) & 0777);

            rename($env, $room.'/original.env');
            symlink($room.'/original.env', $env);
            $this->expectException(RuntimeException::class);
            $target->envPath($env);
        } finally {
            unlink($env);
            rmdir($room.'/app');
            rmdir($room.'/socket');
        }
    }

    public function test_production_refuses_before_reading_the_room(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('splicewire:beam:dev:walk-db', ['action' => 'create'])
            ->expectsOutputToContain('Walk databases are refused in production.')
            ->assertFailed();
    }
}
