<?php

declare(strict_types=1);

namespace Pentacore\Typefinder;

use Illuminate\Support\ServiceProvider;
use Pentacore\Typefinder\Commands\GenerateCommand;

class TypefinderServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/typefinder.php',
            'typefinder'
        );

        $this->app->singleton(TypefinderRegistry::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/typefinder.php' => config_path('typefinder.php'),
            ], 'typefinder-config');
        }
    }
}
