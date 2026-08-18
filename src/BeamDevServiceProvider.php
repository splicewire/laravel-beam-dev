<?php

namespace Splicewire\Beam\Dev;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Dev\Console\DropDbCommand;
use Splicewire\Beam\Dev\Console\IsolatedTestDbCommand;
use Splicewire\Beam\Dev\Databases\DropGuard;
use Splicewire\Beam\Dev\Databases\ScratchDatabases;
use Splicewire\Beam\Dev\Databases\ServerConnection;

/**
 * Local-development tooling for Laravel apps: scratch databases that are cheap to create and safe
 * to destroy.
 *
 * Standalone on purpose. Despite the name it requires nothing from the beam family — it is a plain
 * Laravel package that any project can install, and the `splicewire:beam:dev:` command prefix is
 * naming, not coupling. A dev tool that drags a framework in behind it does not get installed.
 *
 * Commands register only outside production. The package is intended as a `require-dev`, but a
 * project that installs it everywhere (a deployed demo or review environment, where disposable
 * databases are the point) still cannot reach the commands from a production console.
 */
class BeamDevServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam-dev')
            ->hasConfigFile('beam/dev');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ServerConnection::class, fn ($app) => new ServerConnection(
            $app['db'],
            $app['config'],
        ));

        $this->app->singleton(ScratchDatabases::class, fn ($app) => new ScratchDatabases(
            $app->make(ServerConnection::class),
            $app['config'],
        ));

        $this->app->singleton(DropGuard::class, fn ($app) => new DropGuard(
            $app,
            $app->make(ScratchDatabases::class),
        ));
    }

    public function packageBooted(): void
    {
        if ($this->app->runningInConsole() && ! $this->app->environment('production')) {
            $this->commands([
                IsolatedTestDbCommand::class,
                DropDbCommand::class,
            ]);
        }
    }
}
