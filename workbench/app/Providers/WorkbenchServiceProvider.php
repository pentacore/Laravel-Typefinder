<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * A test that starts its own artisan process (tests/Feature/WatchFreshClassesTest.php)
     * configures it through the environment: config values as JSON, a map
     * of class names to the files that declare them (also JSON) for the
     * autoloader, and a PHP file to load while the application boots.
     */
    public function boot(): void
    {
        $config = getenv('TYPEFINDER_WORKBENCH_CONFIG');
        if (is_string($config) && $config !== '') {
            config(json_decode($config, true, 16, JSON_THROW_ON_ERROR));
        }

        $classes = getenv('TYPEFINDER_WORKBENCH_AUTOLOAD');
        if (is_string($classes) && $classes !== '') {
            $files = json_decode($classes, true, 16, JSON_THROW_ON_ERROR);
            spl_autoload_register(static function (string $class) use ($files): void {
                if (isset($files[$class]) && is_file($files[$class])) {
                    require_once $files[$class];
                }
            });
        }

        $preload = getenv('TYPEFINDER_WORKBENCH_PRELOAD');
        if (is_string($preload) && $preload !== '') {
            require_once $preload;
        }
    }
}
