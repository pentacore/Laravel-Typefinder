<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Services\Generator;
use ReflectionClass;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

final class IncrementalRegenTest extends TestCase
{
    private string $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->output = sys_get_temp_dir().'/typefinder-inc-'.uniqid('', true);
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

    public function test_generate_paths_only_processes_targeted_model(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $postPath = $this->output.'/models/Post.d.ts';
        $userPath = $this->output.'/models/User.d.ts';

        $this->assertFileExists($postPath);
        $this->assertFileExists($userPath);

        $postContentBefore = file_get_contents($postPath);

        // Mutate User.d.ts on disk so writeIfChanged detects a difference
        File::put($userPath, '// stale');

        $regenResult = $generator->generatePaths([
            (new ReflectionClass(User::class))->getFileName(),
        ]);

        clearstatcache();

        $this->assertSame($postContentBefore, file_get_contents($postPath), 'Post.d.ts must not be rewritten');
        $this->assertNotEquals('// stale', file_get_contents($userPath), 'User.d.ts must be regenerated');
        $this->assertContains('models/User.d.ts', $regenResult->changed);
        // Barrel and top-level index may or may not appear in changed
        // depending on whether their content actually differs
        $this->assertNotContains('models/Post.d.ts', $regenResult->changed, 'Post must not be in changed list');
    }

    public function test_generate_paths_with_empty_list_does_full_regen(): void
    {
        $generator = app(Generator::class);
        $regenResult = $generator->generatePaths([]);

        $this->assertFileExists($this->output.'/models/User.d.ts');
        $this->assertFileExists($this->output.'/index.d.ts');
        $this->assertNotEmpty($regenResult->changed);
    }

    public function test_generate_paths_ignores_unrecognised_paths_with_warning(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $regenResult = $generator->generatePaths(['/tmp/completely-unrelated.php']);

        $this->assertNotEmpty($regenResult->warnings);
        $this->assertStringContainsString('unrelated.php', implode(' ', $regenResult->warnings));
    }

    public function test_generate_paths_handles_deleted_file_gracefully(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $ghostPath = sys_get_temp_dir().'/ghost-model-'.uniqid('', true).'.php';

        $regenResult = $generator->generatePaths([$ghostPath]);

        // Ghost path matches no category -> warning
        $this->assertNotEmpty($regenResult->warnings);
        $this->assertSame([], $regenResult->failed);
    }
}
