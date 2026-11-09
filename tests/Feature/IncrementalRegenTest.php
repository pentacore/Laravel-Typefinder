<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\ShapeShowcaseResource;
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
            new ReflectionClass(User::class)->getFileName(),
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

    public function test_generate_command_only_flag_rewrites_only_targeted_files(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();

        $postPath = $this->output.'/models/Post.d.ts';
        $userPath = $this->output.'/models/User.d.ts';

        $postMtimeBefore = filemtime($postPath);
        $userMtimeBefore = filemtime($userPath);

        sleep(1);

        $userSourcePath = new ReflectionClass(User::class)->getFileName();
        $this->artisan('typefinder:generate', ['--only' => [$userSourcePath]])->assertSuccessful();

        clearstatcache();

        $this->assertSame($postMtimeBefore, filemtime($postPath));
        $this->assertGreaterThanOrEqual($userMtimeBefore, filemtime($userPath));
    }

    public function test_incremental_phpdoc_resource_matches_full_run(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $output = $this->output.'/resources/ShapeShowcaseResource.d.ts';
        $full = File::get($output);
        File::delete($output);

        $regenResult = $generator->generatePaths([
            (string) new ReflectionClass(ShapeShowcaseResource::class)->getFileName(),
        ]);

        $this->assertSame([], $regenResult->failed);
        $this->assertSame($full, File::get($output));
    }

    public function test_phpdoc_change_is_picked_up_while_class_stays_loaded(): void
    {
        $id = uniqid();
        $dir = sys_get_temp_dir().'/typefinder-inc-doc-'.$id;
        File::ensureDirectoryExists($dir);
        $class = 'IncDoc'.$id.'Resource';
        $file = $dir.'/'.$class.'.php';
        $source = static fn (string $shape): string => <<<PHP
            <?php
            namespace Scratch\\Doc{$id};
            class {$class} extends \\Illuminate\\Http\\Resources\\Json\\JsonResource
            {
                /**
                 * @return {$shape}
                 */
                public function toArray(\\Illuminate\\Http\\Request \$request): array
                {
                    return [];
                }
            }
            PHP;

        config(['typefinder.resources.paths' => [workbench_path('app/Http/Resources'), $dir]]);

        try {
            File::put($file, $source('array{id: int}'));
            $real = (string) realpath($file);
            require $real;

            $generator = app(Generator::class);
            $generator->generatePaths([$real]);
            $output = $this->output.'/resources/'.$class.'.d.ts';
            $this->assertStringNotContainsString('title', File::get($output));

            // The class stays loaded with its old docblock in reflection;
            // the doc must be re-read from disk.
            File::put($file, $source('array{id: int, title: string}'));
            $generator->generatePaths([$real]);

            $this->assertStringContainsString('  title: string;', File::get($output));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_generate_paths_warns_and_drops_output_when_resource_stops_resolving(): void
    {
        $id = uniqid();
        $dir = sys_get_temp_dir().'/typefinder-inc-res-'.$id;
        File::ensureDirectoryExists($dir);
        $file = $dir.'/SwapResource.php';
        $before = 'IncShaped'.$id.'Resource';
        $after = 'IncOrphan'.$id.'Resource';

        config(['typefinder.resources.paths' => [workbench_path('app/Http/Resources'), $dir]]);

        try {
            File::put($file, <<<PHP
                <?php
                namespace Scratch\\Inc{$id};
                #[\\Pentacore\\Typefinder\\Attributes\\TypefinderResource(shape: ['id' => 'number'])]
                class {$before} extends \\Illuminate\\Http\\Resources\\Json\\JsonResource {}
                PHP);
            require realpath($file);

            $generator = app(Generator::class);
            $generator->generateFull();
            $this->assertFileExists($this->output.'/resources/'.$before.'.d.ts');

            // The same file now declares a resource with no attribute and no
            // same-named model: it no longer produces a type.
            File::put($file, <<<PHP
                <?php
                namespace Scratch\\Inc{$id};
                class {$after} extends \\Illuminate\\Http\\Resources\\Json\\JsonResource {}
                PHP);
            require realpath($file);

            $regenResult = $generator->generatePaths([(string) realpath($file)]);

            $this->assertStringContainsString($after, implode("\n", $regenResult->warnings));
            $this->assertFileDoesNotExist($this->output.'/resources/'.$before.'.d.ts');
            $this->assertStringNotContainsString($before, File::get($this->output.'/resources/index.d.ts'));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_generate_paths_drops_output_of_a_deleted_resource(): void
    {
        $id = uniqid();
        $dir = sys_get_temp_dir().'/typefinder-inc-del-'.$id;
        File::ensureDirectoryExists($dir);
        $class = 'IncDeleted'.$id.'Resource';
        $file = $dir.'/'.$class.'.php';

        config(['typefinder.resources.paths' => [workbench_path('app/Http/Resources'), $dir]]);

        try {
            File::put($file, <<<PHP
                <?php
                namespace Scratch\\Del{$id};
                #[\\Pentacore\\Typefinder\\Attributes\\TypefinderResource(shape: ['id' => 'number'])]
                class {$class} extends \\Illuminate\\Http\\Resources\\Json\\JsonResource {}
                PHP);
            $real = (string) realpath($file);
            require $real;

            $generator = app(Generator::class);
            $generator->generateFull();
            $this->assertFileExists($this->output.'/resources/'.$class.'.d.ts');

            File::delete($file);
            $regenResult = $generator->generatePaths([$real]);

            $this->assertSame([], $regenResult->failed);
            $this->assertFileDoesNotExist($this->output.'/resources/'.$class.'.d.ts');
            $this->assertStringNotContainsString($class, File::get($this->output.'/resources/index.d.ts'));
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
