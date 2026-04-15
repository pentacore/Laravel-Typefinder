<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Pentacore\Typefinder\TypefinderRegistry;

use function Orchestra\Testbench\workbench_path;

abstract class TestCase extends OrchestraTestCase
{
    use WithWorkbench;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(workbench_path('database/migrations'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the container-bound registry clean between tests so one test's
        // `Typefinder::registerCast(...)` never leaks into another's assertions.
        $this->app->make(TypefinderRegistry::class)->clearCasts();
    }
}
