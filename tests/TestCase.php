<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

use function Orchestra\Testbench\workbench_path;

abstract class TestCase extends OrchestraTestCase
{
    use WithWorkbench;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(workbench_path('database/migrations'));
    }
}
