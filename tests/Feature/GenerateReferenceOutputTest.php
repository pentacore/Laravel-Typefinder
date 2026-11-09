<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

/**
 * Writes generated .d.ts to a deterministic on-disk location under workbench/
 * so CI can upload the output as an artifact and type-check it with
 * `tsc -p workbench/tsconfig.typefinder.json`. Unlike GenerateCommandTest this
 * does NOT clean up after itself.
 */
final class GenerateReferenceOutputTest extends TestCase
{
    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputPath = workbench_path('resources/js/typefinder');

        if (File::isDirectory($this->outputPath)) {
            File::deleteDirectory($this->outputPath);
        }

        config([
            'typefinder.output_path' => $this->outputPath,
            'typefinder.models.paths' => [workbench_path('app/Models')],
            'typefinder.enums.paths' => [workbench_path('app/Enums')],
            'typefinder.requests.paths' => [workbench_path('app/Http/Requests')],
            'typefinder.resources.paths' => [workbench_path('app/Http/Resources')],
            'typefinder.inertia.enabled' => true,
            'typefinder.inertia.paths' => [workbench_path('app/Http/Controllers')],
            'typefinder.broadcasting.enabled' => true,
            'typefinder.broadcasting.paths' => [workbench_path('app/Events')],
        ]);
    }

    public function test_generates_reference_dts_into_workbench_resources(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();

        $this->assertFileExists($this->outputPath.'/index.d.ts');
        $this->assertFileExists($this->outputPath.'/models/index.d.ts');
        $this->assertFileExists($this->outputPath.'/enums/index.d.ts');
        $this->assertFileExists($this->outputPath.'/requests/index.d.ts');
        // Pivots now live alongside models in the same directory.
        $this->assertFileExists($this->outputPath.'/models/RoleUserPivot.d.ts');
        $this->assertFileExists($this->outputPath.'/resources/index.d.ts');
        $this->assertFileExists($this->outputPath.'/pages.d.ts');
        $this->assertFileExists($this->outputPath.'/broadcasting.d.ts');
        $this->assertFileExists($this->outputPath.'/helpers.d.ts');
    }
}
