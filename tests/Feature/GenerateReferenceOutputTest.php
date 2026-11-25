<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

/**
 * Writes generated output to deterministic on-disk locations under workbench/
 * so CI can type-check it with `tsc -p workbench/tsconfig.typefinder.json`
 * and lint it with `workbench/eslint.typefinder.config.mjs`. Unlike
 * GenerateCommandTest this does NOT clean up after itself.
 *
 * Two trees: `typefinder/` with the default options, and
 * `typefinder-variant/` with the options that change the shape of the
 * output switched away from their defaults.
 */
final class GenerateReferenceOutputTest extends TestCase
{
    public function test_generates_reference_dts_into_workbench_resources(): void
    {
        $outputPath = $this->generate('typefinder');

        $this->assertFileExists($outputPath.'/index.d.ts');
        $this->assertFileExists($outputPath.'/models/index.d.ts');
        $this->assertFileExists($outputPath.'/enums/index.d.ts');
        $this->assertFileExists($outputPath.'/requests/index.d.ts');
        // Pivots now live alongside models in the same directory.
        $this->assertFileExists($outputPath.'/models/RoleUserPivot.d.ts');
        $this->assertFileExists($outputPath.'/resources/index.d.ts');
        $this->assertFileExists($outputPath.'/pages.d.ts');
        $this->assertFileExists($outputPath.'/broadcasting.d.ts');
        $this->assertFileExists($outputPath.'/helpers.d.ts');
    }

    public function test_generates_variant_output_with_non_default_options(): void
    {
        $outputPath = $this->generate('typefinder-variant', [
            'typefinder.enums.emit_values' => true,
            'typefinder.requests.extract_nested' => true,
            'typefinder.models.respect_mass_assignment' => false,
        ]);

        $this->assertFileExists($outputPath.'/index.d.ts');
        $this->assertFileExists($outputPath.'/enums/PostStatus.ts');
        $this->assertFileExists($outputPath.'/models/index.d.ts');
        $this->assertFileExists($outputPath.'/requests/index.d.ts');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function generate(string $directory, array $options = []): string
    {
        $outputPath = workbench_path('resources/js/'.$directory);

        if (File::isDirectory($outputPath)) {
            File::deleteDirectory($outputPath);
        }

        config([
            'typefinder.output_path' => $outputPath,
            'typefinder.models.paths' => [workbench_path('app/Models')],
            'typefinder.enums.paths' => [workbench_path('app/Enums')],
            'typefinder.requests.paths' => [workbench_path('app/Http/Requests')],
            'typefinder.resources.paths' => [workbench_path('app/Http/Resources')],
            'typefinder.inertia.enabled' => true,
            'typefinder.inertia.paths' => [workbench_path('app/Http/Controllers')],
            'typefinder.broadcasting.enabled' => true,
            'typefinder.broadcasting.paths' => [workbench_path('app/Events')],
            ...$options,
        ]);

        $this->artisan('typefinder:generate')->assertSuccessful();

        return $outputPath;
    }
}
