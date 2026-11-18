<?php

namespace Splicewire\Beam\Dev\Tests;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class IsolatedArtisanTest extends TestCase
{
    public function test_native_runner_migrates_runs_literal_argument_tokens_and_reaps_its_database(): void
    {
        $this->app->make(Kernel::class)->registerCommand(new IsolatedArtisanProbe);
        $this->app['migrator']->path(__DIR__.'/fixtures/isolated-artisan/migrations');
        $original = config('database.connections.scratch');
        $this->assertArrayHasKey('splicewire:beam:dev:isolated-artisan', Artisan::all());

        $status = Artisan::call('splicewire:beam:dev:isolated-artisan', [
            '--connection' => 'scratch',
            'command' => 'beam-dev-fixture:inspect',
            'arguments' => ['literal $(not-a-shell) ; value', '--label=two words "quoted"', '--flag'],
        ]);
        $output = Artisan::output();
        $this->assertSame(0, $status, $output);
        $this->assertMatchesRegularExpression('/RUNNER_PROBE:(.+)/', $output);
        preg_match('/RUNNER_PROBE:(.+)/', $output, $match);
        $probe = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('literal $(not-a-shell) ; value', $probe['value']);
        $this->assertSame('two words "quoted"', $probe['label']);
        $this->assertTrue($probe['flag']);
        $this->assertSame('migration-ready', $probe['row']);
        $this->assertSame('scratch', $probe['connection']);
        $this->assertNotSame($original['database'], $probe['database']);
        $this->assertFileDoesNotExist($probe['database']);
        $this->assertSame($original, config('database.connections.scratch'));
        $this->assertSame('scratch', config('database.default'));
        $this->assertSame([], glob($this->scratchDir().'/test_*.sqlite'));
    }
}

class IsolatedArtisanProbe extends Command
{
    protected $signature = 'beam-dev-fixture:inspect {value} {--label=} {--flag}';

    public function handle(): int
    {
        $this->line('RUNNER_PROBE:'.json_encode([
            'value' => $this->argument('value'),
            'label' => $this->option('label'),
            'flag' => $this->option('flag'),
            'row' => DB::table('runner_probe')->value('state'),
            'connection' => DB::getDefaultConnection(),
            'database' => DB::connection()->getDatabaseName(),
        ], JSON_THROW_ON_ERROR));

        return 0;
    }
}
