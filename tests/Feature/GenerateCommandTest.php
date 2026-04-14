<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

class GenerateCommandTest extends TestCase
{
    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputPath = sys_get_temp_dir().'/typefinder-test-'.uniqid();
        config([
            'typefinder.output_path' => $this->outputPath,
            'typefinder.models.paths' => [workbench_path('app/Models')],
            'typefinder.enums.paths' => [workbench_path('app/Enums')],
            'typefinder.requests.paths' => [workbench_path('app/Http/Requests')],
        ]);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->outputPath)) {
            File::deleteDirectory($this->outputPath);
        }
        parent::tearDown();
    }

    public function test_command_runs_successfully(): void
    {
        $this->artisan('typefinder:generate')
            ->assertSuccessful();
    }

    public function test_command_creates_output_directories(): void
    {
        $this->artisan('typefinder:generate');

        $this->assertDirectoryExists($this->outputPath.'/models');
        $this->assertDirectoryExists($this->outputPath.'/enums');
        $this->assertDirectoryExists($this->outputPath.'/requests');
    }

    public function test_command_generates_enum_files(): void
    {
        $this->artisan('typefinder:generate');

        $this->assertFileExists($this->outputPath.'/enums/PostStatus.d.ts');
        $this->assertFileExists($this->outputPath.'/enums/PostTag.d.ts');
        $this->assertFileExists($this->outputPath.'/enums/index.d.ts');

        $content = File::get($this->outputPath.'/enums/PostStatus.d.ts');
        $this->assertStringContainsString("export type PostStatus = 'draft' | 'published' | 'archived';", $content);
    }

    public function test_command_generates_model_files(): void
    {
        $this->artisan('typefinder:generate');

        $this->assertFileExists($this->outputPath.'/models/User.d.ts');
        $this->assertFileExists($this->outputPath.'/models/Post.d.ts');
        $this->assertFileExists($this->outputPath.'/models/Comment.d.ts');
        $this->assertFileExists($this->outputPath.'/models/index.d.ts');

        $content = File::get($this->outputPath.'/models/User.d.ts');
        $this->assertStringContainsString('export type User = {', $content);
        $this->assertStringContainsString('id: number;', $content);
    }

    public function test_command_generates_request_files(): void
    {
        $this->artisan('typefinder:generate');

        $this->assertFileExists($this->outputPath.'/requests/StorePostRequest.d.ts');
        $this->assertFileExists($this->outputPath.'/requests/UpdatePostRequest.d.ts');
        $this->assertFileExists($this->outputPath.'/requests/index.d.ts');
    }

    public function test_command_generates_pivot_files(): void
    {
        $this->artisan('typefinder:generate');

        $this->assertDirectoryExists($this->outputPath.'/pivots');
        $this->assertFileExists($this->outputPath.'/pivots/index.d.ts');
    }

    public function test_command_generates_top_level_barrel(): void
    {
        $this->artisan('typefinder:generate');

        $this->assertFileExists($this->outputPath.'/index.d.ts');
        $content = File::get($this->outputPath.'/index.d.ts');
        $this->assertStringContainsString("export type * from './models';", $content);
        $this->assertStringContainsString("export type * from './enums';", $content);
    }

    public function test_command_respects_disabled_config(): void
    {
        config(['typefinder.enums.enabled' => false]);

        $this->artisan('typefinder:generate');

        $this->assertDirectoryDoesNotExist($this->outputPath.'/enums');
    }

    public function test_command_outputs_info(): void
    {
        $this->artisan('typefinder:generate')
            ->expectsOutputToContain('TypeScript types generated');
    }

    public function test_command_does_not_rewrite_unchanged_files(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();

        $path = $this->outputPath.'/models/User.d.ts';
        $before = filemtime($path);

        sleep(1);

        $this->artisan('typefinder:generate')->assertSuccessful();

        $this->assertSame($before, filemtime($path));
    }

    public function test_json_flag_outputs_single_json_object(): void
    {
        $exitCode = Artisan::call('typefinder:generate', ['--json' => true]);
        $this->assertSame(0, $exitCode);

        $output = Artisan::output();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['success']);
        $this->assertSame($this->outputPath, $decoded['output_path']);
        $this->assertIsInt($decoded['duration_ms']);
        $this->assertArrayHasKey('enums', $decoded['counts']);
        $this->assertArrayHasKey('models', $decoded['counts']);
        $this->assertArrayHasKey('files', $decoded);
    }

    public function test_json_flag_suppresses_normal_output(): void
    {
        Artisan::call('typefinder:generate', ['--json' => true]);

        $output = Artisan::output();

        // Should not contain the human-readable banner
        $this->assertStringNotContainsString('TypeScript types generated successfully.', $output);
        // Should not contain debug prefix either
        $this->assertStringNotContainsString('[typefinder]', $output);
    }

    public function test_debug_flag_outputs_prefixed_lines(): void
    {
        $exitCode = Artisan::call('typefinder:generate', ['--debug' => true]);
        $this->assertSame(0, $exitCode);

        $output = Artisan::output();

        $this->assertStringContainsString('[typefinder] starting', $output);
        $this->assertStringContainsString('[typefinder] done', $output);
        $this->assertStringContainsString('success=true', $output);
    }

    public function test_debug_and_json_together_prefers_json(): void
    {
        $exitCode = Artisan::call('typefinder:generate', ['--debug' => true, '--json' => true]);
        $this->assertSame(0, $exitCode);

        $output = Artisan::output();

        // JSON takes precedence — no [typefinder] prefix output
        $this->assertStringNotContainsString('[typefinder]', $output);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['success']);
    }

    public function test_json_files_entries_reflect_writes(): void
    {
        // First run writes everything
        Artisan::call('typefinder:generate', ['--json' => true]);
        $first = json_decode(Artisan::output(), true);

        // All files on first run should have written=true
        foreach ($first['files'] as $entry) {
            $this->assertTrue($entry['written'], "Expected first-run write for {$entry['path']}");
        }

        // Second run — nothing changed, so all writes should be false
        Artisan::call('typefinder:generate', ['--json' => true]);
        $second = json_decode(Artisan::output(), true);

        foreach ($second['files'] as $entry) {
            $this->assertFalse($entry['written'], "Expected no-op write for {$entry['path']}");
        }
    }
}
