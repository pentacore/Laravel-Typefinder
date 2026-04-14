<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UserController;
use App\Models\User;
use Pentacore\Typefinder\Extractors\ControllerExtractor;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

final class ControllerExtractorTest extends TestCase
{
    private ControllerExtractor $controllerExtractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controllerExtractor = new ControllerExtractor;
    }

    public function test_extracts_typefinder_page_attribute(): void
    {
        $results = $this->controllerExtractor->extractFromDirectory(workbench_path('app/Http/Controllers'));
        $byComponent = collect($results)->keyBy('component');

        $this->assertArrayHasKey('Users/Show', $byComponent->toArray());
        $this->assertSame(User::class, $byComponent['Users/Show']['props']['user']);
        $this->assertSame('boolean', $byComponent['Users/Show']['props']['canEdit']);
        $this->assertSame(
            UserController::class.'::show',
            $byComponent['Users/Show']['source'],
        );
    }

    public function test_extracts_multiple_controllers(): void
    {
        $results = $this->controllerExtractor->extractFromDirectory(workbench_path('app/Http/Controllers'));
        $components = array_column($results, 'component');

        $this->assertContains('Users/Show', $components);
        $this->assertContains('Dashboard', $components);
    }

    public function test_skips_controllers_without_attributes(): void
    {
        $results = $this->controllerExtractor->extractFromDirectory(workbench_path('app/Http/Controllers'));
        $sources = array_column($results, 'source');

        foreach ($sources as $source) {
            $this->assertStringNotContainsString('PlainController', $source);
        }
    }

    public function test_returns_empty_for_missing_directory(): void
    {
        $this->assertSame([], $this->controllerExtractor->extractFromDirectory('/nonexistent'));
    }

    public function test_invokes_progress_callback(): void
    {
        $seen = [];
        $this->controllerExtractor->extractFromDirectory(
            workbench_path('app/Http/Controllers'),
            function (string $cls) use (&$seen): void {
                $seen[] = $cls;
            },
        );

        $this->assertContains(UserController::class, $seen);
        $this->assertContains(DashboardController::class, $seen);
    }
}
