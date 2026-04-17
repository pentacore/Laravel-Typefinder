<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Services\Generator;
use Pentacore\Typefinder\Services\RegenResult;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

final class GeneratorTest extends TestCase
{
    public function test_regen_result_holds_changed_warnings_failed_and_duration(): void
    {
        $regenResult = new RegenResult(
            changed: ['models/User.d.ts', 'models/index.d.ts'],
            warnings: ['App\\Models\\Foo.bar: unknown column type "geography"'],
            failed: [['path' => '/abs/Broken.php', 'message' => 'boom']],
            durationMs: 42,
        );

        $this->assertSame(['models/User.d.ts', 'models/index.d.ts'], $regenResult->changed);
        $this->assertSame(['App\\Models\\Foo.bar: unknown column type "geography"'], $regenResult->warnings);
        $this->assertSame([['path' => '/abs/Broken.php', 'message' => 'boom']], $regenResult->failed);
        $this->assertSame(42, $regenResult->durationMs);
    }

    public function test_generate_full_produces_expected_model_files_in_temp_output(): void
    {
        $output = sys_get_temp_dir().'/typefinder-gen-'.uniqid('', true);
        config([
            'typefinder.output_path' => $output,
            'typefinder.models.paths' => [workbench_path('app/Models')],
            'typefinder.enums.paths' => [workbench_path('app/Enums')],
            'typefinder.requests.paths' => [workbench_path('app/Http/Requests')],
            'typefinder.resources.paths' => [workbench_path('app/Http/Resources')],
        ]);

        $generator = app(Generator::class);
        $regenResult = $generator->generateFull();

        try {
            $this->assertFileExists($output.'/models/User.d.ts');
            $this->assertFileExists($output.'/index.d.ts');
            $this->assertContains('models/User.d.ts', $regenResult->changed);
            $this->assertIsInt($regenResult->durationMs);
            $this->assertGreaterThanOrEqual(0, $regenResult->durationMs);
        } finally {
            File::deleteDirectory($output);
        }
    }

    public function test_generate_full_writes_extraction_cache_to_storage(): void
    {
        $output = sys_get_temp_dir().'/typefinder-gen-'.uniqid('', true);
        config([
            'typefinder.output_path' => $output,
            'typefinder.models.paths' => [workbench_path('app/Models')],
            'typefinder.enums.paths' => [workbench_path('app/Enums')],
            'typefinder.requests.paths' => [workbench_path('app/Http/Requests')],
            'typefinder.resources.paths' => [workbench_path('app/Http/Resources')],
        ]);

        $cachePath = storage_path('framework/cache/typefinder/extractions.json');
        @unlink($cachePath);

        $generator = app(Generator::class);
        $generator->generateFull();

        try {
            $this->assertFileExists($cachePath);
            $raw = (string) file_get_contents($cachePath);
            $decoded = json_decode($raw, true);
            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('composer_lock_hash', $decoded);
            $this->assertArrayHasKey('config_hash', $decoded);
            $this->assertArrayHasKey('entries', $decoded);
            $this->assertNotEmpty($decoded['entries']);
        } finally {
            File::deleteDirectory($output);
            @unlink($cachePath);
        }
    }
}
