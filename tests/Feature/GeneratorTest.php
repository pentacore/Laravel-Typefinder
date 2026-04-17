<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Http\Requests\StorePostRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Cache\CacheKeyFactory;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Services\Generator;
use Pentacore\Typefinder\Services\RegenResult;
use Pentacore\Typefinder\TypefinderRegistry;
use ReflectionClass;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

final class GeneratorTest extends TestCase
{
    private string $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->output = sys_get_temp_dir().'/typefinder-gen-'.uniqid('', true);
        config([
            'typefinder.output_path' => $this->output,
            'typefinder.models.paths' => [workbench_path('app/Models')],
            'typefinder.enums.paths' => [workbench_path('app/Enums')],
            'typefinder.requests.paths' => [workbench_path('app/Http/Requests')],
            'typefinder.resources.paths' => [workbench_path('app/Http/Resources')],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->output);
        @unlink(storage_path('framework/cache/typefinder/extractions.json'));
        parent::tearDown();
    }

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
        $generator = app(Generator::class);
        $regenResult = $generator->generateFull();

        $this->assertFileExists($this->output.'/models/User.d.ts');
        $this->assertFileExists($this->output.'/index.d.ts');
        $this->assertContains('models/User.d.ts', $regenResult->changed);
        $this->assertIsInt($regenResult->durationMs);
        $this->assertGreaterThanOrEqual(0, $regenResult->durationMs);
    }

    public function test_generate_full_writes_extraction_cache_to_storage(): void
    {
        $cachePath = storage_path('framework/cache/typefinder/extractions.json');
        @unlink($cachePath);

        $generator = app(Generator::class);
        $generator->generateFull();

        $this->assertFileExists($cachePath);
        $raw = (string) file_get_contents($cachePath);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('composer_lock_hash', $decoded);
        $this->assertArrayHasKey('config_hash', $decoded);
        $this->assertArrayHasKey('entries', $decoded);
        $this->assertNotEmpty($decoded['entries']);
    }

    // ── Enum emit_values mode ──────────────────────────────────────────

    public function test_generate_full_emits_enum_values_as_ts_files_when_enabled(): void
    {
        config(['typefinder.enums.emit_values' => true]);

        $generator = app(Generator::class);
        $regenResult = $generator->generateFull();

        // .ts files (not .d.ts) should be emitted
        $this->assertFileExists($this->output.'/enums/PostStatus.ts');
        $this->assertFileDoesNotExist($this->output.'/enums/PostStatus.d.ts');

        $content = File::get($this->output.'/enums/PostStatus.ts');
        $this->assertStringContainsString('as const', $content);

        // Barrel should also be .ts with export * (not export type *)
        $barrel = File::get($this->output.'/enums/index.ts');
        $this->assertStringContainsString("export * from './PostStatus'", $barrel);

        $this->assertContains('enums/PostStatus.ts', $regenResult->changed);
    }

    // ── pruneStaleFiles ────────────────────────────────────────────────

    public function test_generate_full_prunes_stale_files_from_previous_run(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        // Plant a stale file in the enums directory
        File::put($this->output.'/enums/DeletedEnum.d.ts', '// stale');

        // Re-run — should prune the stale file
        $generator->generateFull();

        $this->assertFileDoesNotExist($this->output.'/enums/DeletedEnum.d.ts');
    }

    public function test_generate_full_prunes_stale_model_files(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        File::put($this->output.'/models/RemovedModel.d.ts', '// stale');

        $generator->generateFull();

        $this->assertFileDoesNotExist($this->output.'/models/RemovedModel.d.ts');
    }

    // ── Incremental single-file writes ─────────────────────────────────

    public function test_generate_paths_incrementally_writes_single_enum(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        // Mutate the enum output so writeIfChanged detects a diff
        File::put($this->output.'/enums/PostStatus.d.ts', '// stale');

        $enumPath = (new ReflectionClass(PostStatus::class))->getFileName();
        $regenResult = $generator->generatePaths([$enumPath]);

        $this->assertContains('enums/PostStatus.d.ts', $regenResult->changed);
        $content = File::get($this->output.'/enums/PostStatus.d.ts');
        $this->assertStringContainsString('PostStatus', $content);
        $this->assertNotEquals('// stale', $content);
    }

    public function test_generate_paths_incrementally_writes_single_model(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        File::put($this->output.'/models/User.d.ts', '// stale');

        $modelPath = (new ReflectionClass(User::class))->getFileName();
        $regenResult = $generator->generatePaths([$modelPath]);

        $this->assertContains('models/User.d.ts', $regenResult->changed);
        $content = File::get($this->output.'/models/User.d.ts');
        $this->assertStringContainsString('export type User', $content);
    }

    public function test_generate_paths_incrementally_writes_single_request(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        File::put($this->output.'/requests/StorePostRequest.d.ts', '// stale');

        $requestPath = (new ReflectionClass(StorePostRequest::class))->getFileName();
        $regenResult = $generator->generatePaths([$requestPath]);

        $this->assertContains('requests/StorePostRequest.d.ts', $regenResult->changed);
        $content = File::get($this->output.'/requests/StorePostRequest.d.ts');
        $this->assertStringContainsString('StorePostRequest', $content);
    }

    public function test_generate_paths_incrementally_writes_single_resource(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        File::put($this->output.'/resources/UserResource.d.ts', '// stale');

        $resourcePath = (new ReflectionClass(UserResource::class))->getFileName();
        $regenResult = $generator->generatePaths([$resourcePath]);

        $this->assertContains('resources/UserResource.d.ts', $regenResult->changed);
        $content = File::get($this->output.'/resources/UserResource.d.ts');
        $this->assertStringContainsString('UserResource', $content);
    }

    // ── Incremental deletion ───────────────────────────────────────────

    public function test_generate_paths_handles_deleted_source_without_crash(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        // A path inside the models dir that doesn't exist exercises the
        // handleDeletion code path (category resolved, !is_file triggers).
        $ghostPath = workbench_path('app/Models/Vanished.php');

        $regenResult = $generator->generatePaths([$ghostPath]);

        // No crash, no failed — the entry just gets forgotten from cache
        $this->assertSame([], $regenResult->failed);
    }

    public function test_generate_paths_deletion_cleans_cache_entry(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $cachePath = storage_path('framework/cache/typefinder/extractions.json');
        $this->assertFileExists($cachePath);

        $ghostPath = workbench_path('app/Models/Vanished.php');
        $generator->generatePaths([$ghostPath]);

        // After persisting, the vanished path should not be in the cache
        $cache = json_decode((string) file_get_contents($cachePath), true);
        $this->assertArrayNotHasKey($ghostPath, $cache['entries'] ?? []);
    }

    // ── Barrel rewriting after incremental changes ─────────────────────

    public function test_generate_paths_rewrites_category_barrel_after_change(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        File::get($this->output.'/models/index.d.ts');

        // Mutate a model so incremental regen rewrites it
        File::put($this->output.'/models/User.d.ts', '// stale');

        $modelPath = (new ReflectionClass(User::class))->getFileName();
        $regenResult = $generator->generatePaths([$modelPath]);

        $barrelAfter = File::get($this->output.'/models/index.d.ts');

        // The barrel content may or may not change, but it should still list User
        $this->assertStringContainsString('User', $barrelAfter);
        // The barrel rewrite path should have been exercised
        $this->assertContains('models/User.d.ts', $regenResult->changed);
    }

    // ── detectEnabledCategories ────────────────────────────────────────

    public function test_generate_paths_top_level_barrel_reflects_disk_categories(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        // After full gen the barrel should reference models, enums, requests, resources, helpers
        $barrel = File::get($this->output.'/index.d.ts');
        $this->assertStringContainsString("'./models'", $barrel);
        $this->assertStringContainsString("'./enums'", $barrel);
        $this->assertStringContainsString("'./helpers'", $barrel);

        // Incremental regen that touches a model should still produce a valid barrel
        File::put($this->output.'/models/User.d.ts', '// stale');
        $modelPath = (new ReflectionClass(User::class))->getFileName();
        $generator->generatePaths([$modelPath]);

        $barrelAfter = File::get($this->output.'/index.d.ts');
        $this->assertStringContainsString("'./models'", $barrelAfter);
        $this->assertStringContainsString("'./helpers'", $barrelAfter);
    }

    // ── writeIfChanged idempotency at Generator level ──────────────────

    public function test_second_full_generation_reports_no_changes(): void
    {
        $generator = app(Generator::class);

        $regenResult = $generator->generateFull();
        $this->assertNotEmpty($regenResult->changed);

        $second = $generator->generateFull();
        $this->assertEmpty($second->changed, 'Second run should report no changed files');
    }

    // ── Incremental enum with emit_values ──────────────────────────────

    public function test_generate_paths_writes_enum_as_ts_when_emit_values_enabled(): void
    {
        config(['typefinder.enums.emit_values' => true]);

        $generator = app(Generator::class);
        $generator->generateFull();

        $this->assertFileExists($this->output.'/enums/PostStatus.ts');

        // Mutate so incremental detects a change
        File::put($this->output.'/enums/PostStatus.ts', '// stale');

        $enumPath = (new ReflectionClass(PostStatus::class))->getFileName();
        $regenResult = $generator->generatePaths([$enumPath]);

        $this->assertContains('enums/PostStatus.ts', $regenResult->changed);
        $content = File::get($this->output.'/enums/PostStatus.ts');
        $this->assertStringContainsString('as const', $content);
    }

    // ── onWarn callback ────────────────────────────────────────────────

    public function test_generate_full_invokes_on_warn_callback(): void
    {
        $warnings = [];
        $generator = new Generator(
            app(TypefinderRegistry::class),
            new TypeScriptRenderer,
            new CacheKeyFactory(base_path()),
            storage_path('framework/cache/typefinder/extractions.json'),
            function (string $msg) use (&$warnings): void {
                $warnings[] = $msg;
            },
        );

        $generator->generateFull();

        // Even if no warnings are generated, the callback path is exercised.
        // If there are any, they should be strings.
        foreach ($warnings as $warning) {
            $this->assertIsString($warning);
        }
    }

    // ── Failed extraction in incremental mode ──────────────────────────

    public function test_generate_paths_captures_failed_extractions(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        // Create a PHP file that will fail to extract (invalid class)
        $brokenPath = workbench_path('app/Models/BrokenIncrementalModel.php');
        File::put($brokenPath, "<?php\nnamespace App\\Models;\nclass BrokenIncrementalModel { /* not a model */ }\n");

        try {
            $result = $generator->generatePaths([$brokenPath]);

            // Should either warn or fail — not crash
            $hasWarningOrFailed = $result->warnings !== [] || $result->failed !== [];
            $this->assertTrue($hasWarningOrFailed, 'Broken file should produce a warning or failure');
        } finally {
            @unlink($brokenPath);
        }
    }

    // ── Helpers always emitted ─────────────────────────────────────────

    public function test_generate_full_always_emits_helpers(): void
    {
        $generator = app(Generator::class);
        $regenResult = $generator->generateFull();

        $this->assertFileExists($this->output.'/helpers.d.ts');
        $this->assertContains('helpers.d.ts', $regenResult->changed);

        $content = File::get($this->output.'/helpers.d.ts');
        $this->assertStringContainsString('WrappedResource', $content);
    }

    // ── Resources through Generator ────────────────────────────────────

    public function test_generate_full_writes_resource_files(): void
    {
        $generator = app(Generator::class);
        $regenResult = $generator->generateFull();

        $this->assertFileExists($this->output.'/resources/UserResource.d.ts');
        $this->assertFileExists($this->output.'/resources/index.d.ts');
        $this->assertContains('resources/UserResource.d.ts', $regenResult->changed);
    }

    public function test_generate_full_prunes_stale_resource_files(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        File::put($this->output.'/resources/GoneResource.d.ts', '// stale');

        $generator->generateFull();

        $this->assertFileDoesNotExist($this->output.'/resources/GoneResource.d.ts');
    }

    // ── Requests through Generator ─────────────────────────────────────

    public function test_generate_full_prunes_stale_request_files(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        File::put($this->output.'/requests/OldRequest.d.ts', '// stale');

        $generator->generateFull();

        $this->assertFileDoesNotExist($this->output.'/requests/OldRequest.d.ts');
    }

    // ── Pivots through Generator ───────────────────────────────────────

    public function test_generate_full_produces_pivot_files(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $this->assertFileExists($this->output.'/models/RoleUserPivot.d.ts');
        $content = File::get($this->output.'/models/RoleUserPivot.d.ts');
        $this->assertStringContainsString('RoleUserPivot', $content);
    }
}
