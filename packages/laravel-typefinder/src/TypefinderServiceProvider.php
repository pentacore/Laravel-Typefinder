<?php

declare(strict_types=1);

namespace Pentacore\Typefinder;

use Illuminate\Support\ServiceProvider;
use Pentacore\Typefinder\Cache\CacheKeyFactory;
use Pentacore\Typefinder\Commands\GenerateCommand;
use Pentacore\Typefinder\Commands\WatchCommand;
use Pentacore\Typefinder\Facades\Typefinder;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Services\Generator;

/**
 * Package service provider. Auto-discovered via Laravel's package discovery —
 * host applications don't need to register it manually.
 *
 * Responsibilities:
 *   - Merge the package's `config/typefinder.php` into the host app's config
 *     (so `config('typefinder.output_path')` works out of the box).
 *   - Bind {@see TypefinderRegistry} as a singleton so
 *     {@see Typefinder} and any direct
 *     container resolution share the same registry state.
 *   - Register the `typefinder:generate` artisan command in console contexts.
 *   - Expose the default config for publishing via `vendor:publish`.
 */
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

        $this->app->singleton(Generator::class, fn ($app): Generator => new Generator(
            $app->make(TypefinderRegistry::class),
            new TypeScriptRenderer,
            new CacheKeyFactory(base_path()),
            storage_path('framework/cache/typefinder/extractions.json'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateCommand::class,
                WatchCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/typefinder.php' => config_path('typefinder.php'),
            ], 'typefinder-config');
        }
    }
}
