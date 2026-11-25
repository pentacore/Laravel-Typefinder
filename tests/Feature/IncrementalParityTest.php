<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Services\Generator;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

/**
 * An incremental run leaves the output tree as a full run would: both emit
 * from the same extraction set (#110, #111).
 */
final class IncrementalParityTest extends TestCase
{
    private string $output;

    private string $scratch;

    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = str_replace('.', '', uniqid('', true));
        $this->output = sys_get_temp_dir().'/typefinder-parity-out-'.$this->id;
        $this->scratch = sys_get_temp_dir().'/typefinder-parity-src-'.$this->id;
        File::ensureDirectoryExists($this->scratch);

        config([
            'typefinder.output_path' => $this->output,
            'typefinder.models.paths' => [workbench_path('app/Models')],
            'typefinder.enums.paths' => [workbench_path('app/Enums')],
            'typefinder.requests.paths' => [workbench_path('app/Http/Requests')],
            'typefinder.resources.paths' => [workbench_path('app/Http/Resources')],
            'typefinder.inertia.enabled' => true,
            'typefinder.inertia.paths' => [workbench_path('app/Http/Controllers')],
            'typefinder.broadcasting.enabled' => true,
            'typefinder.broadcasting.paths' => [workbench_path('app/Events')],
        ]);
        $this->app->useAppPath(workbench_path('app'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->output);
        File::deleteDirectory($this->scratch);
        @unlink(storage_path('framework/cache/typefinder/extractions.json'));
        parent::tearDown();
    }

    public function test_incremental_run_of_any_source_file_leaves_the_full_output(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $full = $this->tree();

        foreach ((array) config('typefinder') as $category) {
            foreach ((array) ($category['paths'] ?? []) as $dir) {
                foreach (Finder::create()->files()->name('*.php')->in($dir)->sortByName() as $source) {
                    $generator->generatePaths([$source->getRealPath()]);

                    $this->assertSame($full, $this->tree(), $source->getFilename().' changed the output');
                }
            }
        }
    }

    public function test_incremental_run_rebuilds_a_wiped_output_tree(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $full = $this->tree();

        File::cleanDirectory($this->output);
        $regenResult = $generator->generatePaths([workbench_path('app/Models/User.php')]);

        $this->assertSame([], $regenResult->failed);
        $this->assertSame($full, $this->tree());
    }

    public function test_incremental_run_keeps_the_morph_targets_of_a_model(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $full = File::get($this->output.'/models/Comment.d.ts');
        $this->assertStringContainsString('export type Comment<T extends Post = Post>', $full);

        $generator->generatePaths([workbench_path('app/Models/Comment.php')]);

        $this->assertSame($full, File::get($this->output.'/models/Comment.d.ts'));
    }

    public function test_incremental_run_writes_a_pivot_type_that_is_missing(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $pivot = File::get($this->output.'/models/RoleUserPivot.d.ts');
        $barrel = File::get($this->output.'/models/index.d.ts');

        // The state before the relation existed: no pivot file yet.
        File::delete($this->output.'/models/RoleUserPivot.d.ts');
        $generator->generatePaths([workbench_path('app/Models/User.php')]);

        $this->assertFileExists($this->output.'/models/RoleUserPivot.d.ts');
        $this->assertSame($pivot, File::get($this->output.'/models/RoleUserPivot.d.ts'));
        $this->assertSame($barrel, File::get($this->output.'/models/index.d.ts'));
    }

    public function test_incremental_run_prunes_a_pivot_type_no_relation_refers_to(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        // Left behind by a relation that has since been removed.
        File::put($this->output.'/models/OldLinkPivot.d.ts', "export type OldLinkPivot = {\n};\n");
        $generator->generatePaths([workbench_path('app/Models/User.php')]);

        $this->assertFileDoesNotExist($this->output.'/models/OldLinkPivot.d.ts');
        $this->assertStringNotContainsString('OldLinkPivot', File::get($this->output.'/models/index.d.ts'));
    }

    public function test_incremental_run_refreshes_broadcasting_after_an_event_edit(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $full = File::get($this->output.'/broadcasting.d.ts');

        File::put($this->output.'/broadcasting.d.ts', '// stale');
        $regenResult = $generator->generatePaths([workbench_path('app/Events/OrderShipped.php')]);

        $this->assertSame([], $regenResult->failed);
        $this->assertContains('broadcasting.d.ts', $regenResult->changed);
        $this->assertSame($full, File::get($this->output.'/broadcasting.d.ts'));
    }

    public function test_incremental_run_handles_a_directory_two_categories_share(): void
    {
        $this->writeEnum('SharedKind');
        config([
            'typefinder.enums.paths' => [$this->scratch],
            'typefinder.requests.paths' => [$this->scratch],
        ]);

        $generator = app(Generator::class);
        $generator->generateFull();
        $this->assertFileExists($this->output.'/enums/SharedKind.d.ts');

        $request = $this->writeRequest('SharedStoreRequest');
        $regenResult = $generator->generatePaths([$request]);

        $this->assertSame([], $regenResult->failed);
        $this->assertFileExists($this->output.'/requests/SharedStoreRequest.d.ts');
        $this->assertStringContainsString('SharedStoreRequest', File::get($this->output.'/requests/index.d.ts'));
        $this->assertFileExists($this->output.'/enums/SharedKind.d.ts');
    }

    public function test_incremental_run_picks_up_a_file_it_was_not_told_about(): void
    {
        $first = $this->writeEnum('ToldKind');
        config(['typefinder.enums.paths' => [$this->scratch]]);

        $generator = app(Generator::class);
        $generator->generateFull();

        // Added while nothing was watching, e.g. by a git pull.
        $this->writeEnum('UntoldKind');
        $regenResult = $generator->generatePaths([$first]);

        $this->assertSame([], $regenResult->failed);
        $this->assertFileExists($this->output.'/enums/UntoldKind.d.ts');
        $this->assertStringContainsString('UntoldKind', File::get($this->output.'/enums/index.d.ts'));
    }

    public function test_incremental_run_with_a_cold_cache_is_a_full_run(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $full = $this->tree();

        // Without the cache the other types are unknown, so they are all extracted again.
        @unlink(storage_path('framework/cache/typefinder/extractions.json'));
        File::cleanDirectory($this->output);
        $generator->generatePaths([workbench_path('app/Models/User.php')]);

        $this->assertSame($full, $this->tree());
    }

    public function test_full_run_removes_the_output_of_a_category_that_became_empty(): void
    {
        $enum = $this->writeEnum('LastKind');
        config(['typefinder.enums.paths' => [$this->scratch]]);

        $generator = app(Generator::class);
        $generator->generateFull();
        $this->assertFileExists($this->output.'/enums/LastKind.d.ts');

        File::delete($enum);
        $generator->generateFull();

        $this->assertFileDoesNotExist($this->output.'/enums/LastKind.d.ts');
        $this->assertFileDoesNotExist($this->output.'/enums/index.d.ts');
        $this->assertStringNotContainsString("'./enums'", File::get($this->output.'/index.d.ts'));
    }

    public function test_incremental_run_removes_the_output_of_a_category_that_became_empty(): void
    {
        $enum = $this->writeEnum('OnlyKind');
        config(['typefinder.enums.paths' => [$this->scratch]]);

        $generator = app(Generator::class);
        $generator->generateFull();
        $this->assertFileExists($this->output.'/enums/OnlyKind.d.ts');

        File::delete($enum);
        $generator->generatePaths([$enum]);

        $this->assertFileDoesNotExist($this->output.'/enums/OnlyKind.d.ts');
        $this->assertFileDoesNotExist($this->output.'/enums/index.d.ts');
        $this->assertStringNotContainsString("'./enums'", File::get($this->output.'/index.d.ts'));
    }

    public function test_full_run_removes_a_single_file_output_with_nothing_left_to_emit(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();
        $this->assertFileExists($this->output.'/pages.d.ts');
        $this->assertFileExists($this->output.'/broadcasting.d.ts');

        config([
            'typefinder.inertia.paths' => [$this->scratch],
            'typefinder.broadcasting.paths' => [$this->scratch],
        ]);
        $generator->generateFull();

        // pages.d.ts stays for SharedPageProps, with no page in PageProps (#231).
        $this->assertStringContainsString("export type PageProps = {};\n", File::get($this->output.'/pages.d.ts'));
        $this->assertFileDoesNotExist($this->output.'/broadcasting.d.ts');
    }

    public function test_incremental_run_drops_the_type_of_a_resource_whose_model_was_deleted(): void
    {
        $model = $this->writeModel('Widget');
        $this->writeResource('WidgetResource');
        $this->useScratchModelsAndResources();

        $generator = app(Generator::class);
        $generator->generateFull();
        $this->assertStringContainsString('export type WidgetResource = Widget;', File::get($this->output.'/resources/WidgetResource.d.ts'));

        File::delete($model);
        $regenResult = $generator->generatePaths([$model]);

        $this->assertSame([], $regenResult->failed);
        $this->assertFileDoesNotExist($this->output.'/models/Widget.d.ts');
        $this->assertFileDoesNotExist($this->output.'/resources/WidgetResource.d.ts');
        $this->assertContains('resources/WidgetResource.d.ts', $regenResult->removed);
    }

    public function test_incremental_run_types_a_resource_by_a_model_that_was_added(): void
    {
        $this->writeResource('GadgetResource');
        $this->useScratchModelsAndResources();

        $generator = app(Generator::class);
        $generator->generateFull();
        $this->assertFileDoesNotExist($this->output.'/resources/GadgetResource.d.ts');

        $model = $this->writeModel('Gadget');
        $regenResult = $generator->generatePaths([$model]);

        $this->assertSame([], $regenResult->failed);
        $this->assertStringContainsString('export type GadgetResource = Gadget;', File::get($this->output.'/resources/GadgetResource.d.ts'));
    }

    public function test_disabling_a_category_removes_its_output(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();
        $this->assertFileExists($this->output.'/resources/UserResource.d.ts');
        $this->assertFileExists($this->output.'/pages.d.ts');
        $this->assertFileExists($this->output.'/broadcasting.d.ts');

        config([
            'typefinder.resources.enabled' => false,
            'typefinder.inertia.enabled' => false,
            'typefinder.broadcasting.enabled' => false,
        ]);
        $regenResult = $generator->generateFull();

        $this->assertDirectoryDoesNotExist($this->output.'/resources');
        $this->assertFileDoesNotExist($this->output.'/pages.d.ts');
        $this->assertFileDoesNotExist($this->output.'/broadcasting.d.ts');
        $this->assertContains('resources/UserResource.d.ts', $regenResult->removed);
        $this->assertContains('pages.d.ts', $regenResult->removed);
        $this->assertStringNotContainsString("'./resources'", File::get($this->output.'/index.d.ts'));
    }

    public function test_incremental_run_sees_a_column_a_migration_added(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();
        $this->assertStringNotContainsString('nickname', File::get($this->output.'/models/User.d.ts'));

        $migrations = $this->scratch.'/migrations';
        File::ensureDirectoryExists($migrations);
        File::put($migrations.'/2030_01_01_000000_add_nickname_to_users.php', <<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;
            return new class extends Migration {
                public function up(): void
                {
                    Schema::table('users', fn (Blueprint $table) => $table->string('nickname')->nullable());
                }
            };
            PHP);
        $this->artisan('migrate', ['--path' => $migrations, '--realpath' => true])->assertSuccessful();

        $regenResult = $generator->generatePaths([workbench_path('app/Models/Post.php')]);

        $this->assertSame([], $regenResult->failed);
        $this->assertStringContainsString('nickname', File::get($this->output.'/models/User.d.ts'));
    }

    public function test_incremental_run_extracts_a_class_again_when_its_parent_changed(): void
    {
        $child = (string) realpath(workbench_path('app/Http/Resources/InheritedShapeResource.php'));
        $parent = (string) realpath(workbench_path('app/Http/Resources/Concerns/BaseShapeResource.php'));
        $mtime = (int) filemtime($parent);

        $generator = app(Generator::class);
        $generator->generateFull();
        $this->assertSame([$mtime, filesize($parent)], $this->cachedDependency('resources', $child, $parent));

        try {
            touch($parent, $mtime + 5);
            $generator->generatePaths([$parent]);

            $this->assertSame([$mtime + 5, filesize($parent)], $this->cachedDependency('resources', $child, $parent), 'the child was extracted again');
        } finally {
            touch($parent, $mtime);
        }
    }

    /**
     * @return mixed what the persisted cache records about one dependency of a file
     */
    private function cachedDependency(string $category, string $file, string $dependency): mixed
    {
        $cache = json_decode(File::get(storage_path('framework/cache/typefinder/extractions.json')), true, 512, JSON_THROW_ON_ERROR);

        return $cache['entries'][$category][$file]['deps'][$dependency] ?? null;
    }

    private function useScratchModelsAndResources(): void
    {
        config([
            'typefinder.models.paths' => [$this->scratch.'/Models'],
            'typefinder.resources.paths' => [$this->scratch.'/Resources'],
            'typefinder.inertia.enabled' => false,
            'typefinder.broadcasting.enabled' => false,
        ]);
    }

    /**
     * Write a model of the users table into the scratch dir and load it.
     */
    private function writeModel(string $name): string
    {
        File::ensureDirectoryExists($this->scratch.'/Models');
        $file = $this->scratch.'/Models/'.$name.'.php';
        File::put($file, "<?php\nnamespace Scratch\\Parity{$this->id}\\Models;\nclass {$name} extends \\Illuminate\\Database\\Eloquent\\Model { protected \$table = 'users'; }\n");
        require $file;

        return (string) realpath($file);
    }

    /**
     * Write a resource without a declared shape into the scratch dir and load it.
     */
    private function writeResource(string $name): string
    {
        File::ensureDirectoryExists($this->scratch.'/Resources');
        $file = $this->scratch.'/Resources/'.$name.'.php';
        File::put($file, "<?php\nnamespace Scratch\\Parity{$this->id}\\Resources;\nclass {$name} extends \\Illuminate\\Http\\Resources\\Json\\JsonResource {}\n");
        require $file;

        return (string) realpath($file);
    }

    /**
     * @return array<string, string> file contents by path relative to the output dir
     */
    private function tree(): array
    {
        $tree = [];
        foreach (Finder::create()->files()->in($this->output)->sortByName() as $file) {
            $tree[$file->getRelativePathname()] = $file->getContents();
        }

        return $tree;
    }

    /**
     * Write a backed enum into the scratch dir and load it.
     */
    private function writeEnum(string $name): string
    {
        $file = $this->scratch.'/'.$name.'.php';
        File::put($file, "<?php\nnamespace Scratch\\Parity{$this->id};\nenum {$name}: string { case One = 'one'; }\n");
        require $file;

        return (string) realpath($file);
    }

    /**
     * Write a form request into the scratch dir and load it.
     */
    private function writeRequest(string $name): string
    {
        $file = $this->scratch.'/'.$name.'.php';
        File::put($file, <<<PHP
            <?php
            namespace Scratch\\Parity{$this->id};
            class {$name} extends \\Illuminate\\Foundation\\Http\\FormRequest
            {
                public function rules(): array
                {
                    return ['title' => ['required', 'string']];
                }
            }
            PHP);
        require $file;

        return (string) realpath($file);
    }
}
