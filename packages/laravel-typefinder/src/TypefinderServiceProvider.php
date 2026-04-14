<?php

namespace Pentacore\Typefinder;

use Illuminate\Support\ServiceProvider;
use Pentacore\Typefinder\Commands\GenerateCommand;

class TypefinderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/typefinder.php',
            'typefinder'
        );
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

            $this->publishes([
                __DIR__.'/../resources/boost/skills/typefinder' => base_path('.claude/skills/typefinder'),
            ], 'typefinder-skill');

            $this->publishes([
                __DIR__.'/../resources/boost/guidelines/core.blade.php' => base_path('.ai/guidelines/typefinder/core.blade.php'),
            ], 'typefinder-boost-guidelines');

            $this->publishes([
                __DIR__.'/../config/typefinder.php' => config_path('typefinder.php'),
                __DIR__.'/../resources/boost/skills/typefinder' => base_path('.claude/skills/typefinder'),
                __DIR__.'/../resources/boost/guidelines/core.blade.php' => base_path('.ai/guidelines/typefinder/core.blade.php'),
            ], 'typefinder-all');
        }
    }
}
