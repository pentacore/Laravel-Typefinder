<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

/**
 * Writes generated .d.ts to a deterministic on-disk location under workbench/
 * so CI can upload the output as an artifact. Unlike GenerateCommandTest this
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
    }
}
