<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Cache\CacheKeyFactory;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Services\Generator;
use Pentacore\Typefinder\TypefinderRegistry;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

/**
 * Issue #104: a category barrel only re-exported the type each file is named
 * after, so `{Model}Create` / `{Model}Update`, `{Request}Query` and extracted
 * nested request types could not be imported from the barrel.
 */
final class BarrelCompanionExportsTest extends TestCase
{
    private string $output;

    private string $scratch;

    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = uniqid();
        $this->output = sys_get_temp_dir().'/typefinder-barrel-out-'.$this->id;
        $this->scratch = sys_get_temp_dir().'/typefinder-barrel-src-'.$this->id;
        File::ensureDirectoryExists($this->scratch);

        config([
            'typefinder.output_path' => $this->output,
            'typefinder.models.paths' => [workbench_path('app/Models')],
            'typefinder.enums.paths' => [workbench_path('app/Enums')],
            'typefinder.requests.paths' => [__DIR__.'/../Fixtures/Requests'],
            'typefinder.resources.enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->output);
        File::deleteDirectory($this->scratch);
        @unlink(storage_path('framework/cache/typefinder/extractions.json'));
        parent::tearDown();
    }

    public function test_exported_type_names_lists_the_files_own_type_first(): void
    {
        $content = "import type { A } from './A';\n\nexport type ThingMeta = {\n  a: A;\n};\n\nexport type Thing<T = unknown> = {\n  meta: ThingMeta;\n};\n\nexport type ThingQuery = Record<string, never>;\n";

        $this->assertSame(['Thing', 'ThingMeta', 'ThingQuery'], TypeScriptRenderer::exportedTypeNames($content, 'Thing'));
    }

    public function test_barrel_index_lists_every_type_of_a_file(): void
    {
        $output = new TypeScriptRenderer()->renderBarrelIndex([
            'User' => ['User', 'UserCreate', 'UserUpdate'],
            'RoleUserPivot' => ['RoleUserPivot'],
        ]);

        $this->assertStringContainsString("export type { RoleUserPivot } from './RoleUserPivot';\nexport type { User, UserCreate, UserUpdate } from './User';\n", $output);
    }

    public function test_model_barrel_re_exports_the_write_shapes(): void
    {
        app(Generator::class)->generateFull();

        $barrel = File::get($this->output.'/models/index.d.ts');
        $this->assertStringContainsString("export type { User, UserCreate, UserUpdate } from './User';", $barrel);
        $this->assertStringContainsString("export type { RoleUserPivot } from './RoleUserPivot';", $barrel);
    }

    public function test_model_barrel_without_write_shapes_is_unchanged(): void
    {
        config(['typefinder.models.emit_write_shapes' => false]);

        app(Generator::class)->generateFull();

        $this->assertStringContainsString("export type { User } from './User';", File::get($this->output.'/models/index.d.ts'));
    }

    public function test_request_barrel_re_exports_query_and_nested_types(): void
    {
        config(['typefinder.requests.extract_nested' => true]);

        app(Generator::class)->generateFull();

        $barrel = File::get($this->output.'/requests/index.d.ts');
        $this->assertStringContainsString("export type { Issue101QueryRequest, Issue101QueryRequestQuery } from './Issue101QueryRequest';", $barrel);
        $this->assertStringContainsString(
            "export type { Issue88ReproRequest, Issue88ReproRequestRequest, Issue88ReproRequestRequestHeaders, Issue88ReproRequestRequestMeta, Issue88ReproRequestItems, Issue88ReproRequestItemsAddress } from './Issue88ReproRequest';",
            $barrel,
        );
    }

    public function test_incremental_run_writes_the_same_barrels(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $models = File::get($this->output.'/models/index.d.ts');
        $requests = File::get($this->output.'/requests/index.d.ts');
        File::delete([$this->output.'/models/index.d.ts', $this->output.'/requests/index.d.ts']);
        // Not a generated file: the barrel must not pick it up.
        File::put($this->output.'/models/notes.txt', 'export type Stray = string;');

        $regenResult = $generator->generatePaths([
            workbench_path('app/Models/User.php'),
            (string) realpath(__DIR__.'/../Fixtures/Requests/Issue101QueryRequest.php'),
        ]);

        $this->assertSame([], $regenResult->failed);
        $this->assertSame($models, File::get($this->output.'/models/index.d.ts'));
        $this->assertSame($requests, File::get($this->output.'/requests/index.d.ts'));
    }

    public function test_companion_named_like_another_file_stays_out_of_the_barrel(): void
    {
        // `StoreThingRequestQuery` is both a request of its own and the query type of `StoreThingRequest`.
        $this->writeRequest('StoreThingRequest', "/** @query */\n            'page' => ['integer'],\n            'name' => ['string'],");
        $this->writeRequest('StoreThingRequestQuery', "'term' => ['string'],");
        config(['typefinder.requests.paths' => [$this->scratch]]);

        $heard = [];
        $generator = new Generator(
            app(TypefinderRegistry::class),
            new TypeScriptRenderer,
            new CacheKeyFactory(base_path()),
            storage_path('framework/cache/typefinder/extractions.json'),
            function (string $message) use (&$heard): void {
                $heard[] = $message;
            },
        );
        $regenResult = $generator->generateFull();

        $barrel = File::get($this->output.'/requests/index.d.ts');
        $this->assertStringContainsString("export type { StoreThingRequest } from './StoreThingRequest';", $barrel);
        $this->assertStringContainsString("export type { StoreThingRequestQuery } from './StoreThingRequestQuery';", $barrel);
        $this->assertSame(1, substr_count($barrel, 'StoreThingRequestQuery }'));

        $expected = 'requests: StoreThingRequestQuery is exported by StoreThingRequest.d.ts and StoreThingRequestQuery.d.ts. '
            .'The barrel re-exports the one from StoreThingRequestQuery.d.ts; import any other from its file.';
        $this->assertContains($expected, $regenResult->warnings);

        // An incremental run keeps the barrel and repeats the warning.
        $regenResult = $generator->generatePaths([(string) realpath($this->scratch.'/StoreThingRequest.php')]);

        $this->assertSame([], $regenResult->failed);
        $this->assertSame($barrel, File::get($this->output.'/requests/index.d.ts'));
        $this->assertContains($expected, $regenResult->warnings);
        // The warning callback hears it from both runs.
        $this->assertCount(2, array_keys($heard, $expected, true));
    }

    public function test_companion_exported_by_two_files_stays_out_of_the_barrel(): void
    {
        // Both nested types are named `StoreItemsMeta`.
        $this->writeRequest('Store', "'itemsMeta' => ['array'],\n            'itemsMeta.a' => ['string'],");
        $this->writeRequest('StoreItems', "'meta' => ['array'],\n            'meta.b' => ['string'],");
        config(['typefinder.requests.paths' => [$this->scratch], 'typefinder.requests.extract_nested' => true]);

        $regenResult = app(Generator::class)->generateFull();

        $barrel = File::get($this->output.'/requests/index.d.ts');
        $this->assertStringContainsString("export type { Store } from './Store';", $barrel);
        $this->assertStringContainsString("export type { StoreItems } from './StoreItems';", $barrel);
        $this->assertStringNotContainsString('StoreItemsMeta', $barrel);
        $this->assertContains(
            'requests: StoreItemsMeta is exported by Store.d.ts and StoreItems.d.ts. '
            .'The barrel re-exports neither; import it from its file.',
            $regenResult->warnings,
        );
    }

    public function test_companion_named_like_a_type_of_another_category_warns(): void
    {
        File::put($this->scratch.'/UserCreate.php', "<?php\nnamespace Scratch\\Barrel{$this->id};\nenum UserCreate: string { case Yes = 'yes'; }\n");
        require $this->scratch.'/UserCreate.php';
        config(['typefinder.enums.paths' => [workbench_path('app/Enums'), $this->scratch]]);

        $regenResult = app(Generator::class)->generateFull();

        $warning = collect($regenResult->warnings)->first(fn (string $w): bool => str_contains($w, 'UserCreate is emitted by both') && str_contains($w, 'enums') && str_contains($w, 'models'));
        $this->assertNotNull($warning, 'expected a cross-category warning, got: '.implode(' | ', $regenResult->warnings));
    }

    public function test_value_enum_barrel_is_unchanged(): void
    {
        config(['typefinder.enums.emit_values' => true]);

        app(Generator::class)->generateFull();

        $this->assertStringContainsString("export * from './PostStatus';", File::get($this->output.'/enums/index.ts'));
    }

    public function test_incremental_run_rewrites_the_value_enum_barrel(): void
    {
        config(['typefinder.enums.emit_values' => true]);

        $generator = app(Generator::class);
        $generator->generateFull();

        $barrel = File::get($this->output.'/enums/index.ts');
        File::delete($this->output.'/enums/index.ts');

        $regenResult = $generator->generatePaths([workbench_path('app/Enums/PostStatus.php')]);

        $this->assertSame([], $regenResult->failed);
        $this->assertContains('enums/index.ts', $regenResult->changed);
        $this->assertSame($barrel, File::get($this->output.'/enums/index.ts'));
    }

    /**
     * Write a form request into the scratch dir and load it.
     */
    private function writeRequest(string $short, string $rules): void
    {
        $file = $this->scratch.'/'.$short.'.php';
        File::put($file, <<<PHP
            <?php
            namespace Scratch\\Barrel{$this->id};
            class {$short} extends \\Illuminate\\Foundation\\Http\\FormRequest
            {
                public function rules(): array
                {
                    return [
                        {$rules}
                    ];
                }
            }

            PHP);
        require $file;
    }
}
