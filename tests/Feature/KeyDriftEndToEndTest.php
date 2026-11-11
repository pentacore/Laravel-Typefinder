<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\DriftingPageController;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ConditionalKeysResource;
use App\Http\Resources\DoubleDeclaredResource;
use App\Http\Resources\DriftingResource;
use App\Http\Resources\OpenReturnResource;
use App\Http\Resources\TagResource;
use Closure;
use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Services\Generator;
use RuntimeException;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

final class KeyDriftEndToEndTest extends TestCase
{
    private string $output;

    private ?string $scratch = null;

    private ?Closure $autoloader = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->output = sys_get_temp_dir().'/typefinder-drift-'.uniqid('', true);
        config([
            'typefinder.output_path' => $this->output,
            'typefinder.models.paths' => [workbench_path('app/Models')],
            'typefinder.enums.paths' => [workbench_path('app/Enums')],
            'typefinder.requests.paths' => [workbench_path('app/Http/Requests')],
            'typefinder.resources.paths' => [workbench_path('app/Http/Resources')],
            'typefinder.inertia.enabled' => true,
            'typefinder.inertia.paths' => [workbench_path('app/Http/Controllers')],
        ]);
        // Shared Inertia props are read from app_path('Http/Middleware').
        $this->app->useAppPath(workbench_path('app'));
    }

    protected function tearDown(): void
    {
        if ($this->autoloader instanceof Closure) {
            spl_autoload_unregister($this->autoloader);
        }

        if ($this->scratch !== null) {
            File::deleteDirectory($this->scratch);
        }

        File::deleteDirectory($this->output);
        @unlink(storage_path('framework/cache/typefinder/extractions.json'));
        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function driftWarnings(array $warnings): array
    {
        // Mapping (`@return:`) and composition warnings mention toArray() too but are not drift.
        return array_values(array_filter($warnings, static fn (string $w): bool => (str_contains($w, '::toArray()') && ! str_contains($w, '::toArray() @return') && ! str_contains($w, '::toArray() composes a shape'))
            || str_contains($w, 'Inertia page')
            || str_contains($w, '#[TypefinderPage]')
            || str_contains($w, 'shape and PHPDoc')));
    }

    /**
     * @return list<string>
     */
    private function resourceDrift(): array
    {
        return [
            DriftingResource::class.'::toArray() returns key "name" not declared in #[TypefinderResource] shape',
            DriftingResource::class.'::toArray(): #[TypefinderResource] shape declares "title" but toArray() never returns it',
            OpenReturnResource::class.'::toArray() returns key "extra" not declared in #[TypefinderResource] shape',
            ConditionalKeysResource::class.'::toArray() returns key "stats" not declared in PHPDoc @return shape',
            ConditionalKeysResource::class.'::toArray(): PHPDoc @return shape declares "name" as required but toArray() does not always return it (make it optional?)',
            ConditionalKeysResource::class.'::toArray(): "posts" is conditional in code but required in PHPDoc @return shape',
            ConditionalKeysResource::class.'::toArray(): "meta" is conditional in code but required in PHPDoc @return shape',
            DoubleDeclaredResource::class.': #[TypefinderResource] shape and PHPDoc @return shape disagree (only in attribute: {title, status}; only in PHPDoc: {name})',
        ];
    }

    /**
     * @return list<string>
     */
    private function modelDrift(): array
    {
        $by = ' — adjust it with #[TypefinderResource(model: …, omit: …, extend: …)], declare its shape with #[TypefinderResource(shape: …)] or a PHPDoc @return array{…} on toArray(), or set typefinder.resources.infer_from_model_name to false';

        return [
            CategoryResource::class.'::toArray() does not match the Category model type it is typed as (by name): returns undeclared "slug"; never returns "parent_id", "featured_status", "owner_snapshot", "root_snapshot", "created_at", "updated_at"'.$by,
            TagResource::class.'::toArray() does not match the Tag model type it is typed as (by name): returns undeclared "x"'.$by,
        ];
    }

    /**
     * @return list<string>
     */
    private function pageDrift(): array
    {
        return [
            DriftingPageController::class.'::show() passes prop "c" to Inertia page "Drift/Show" but #[TypefinderPage] does not declare it',
            DriftingPageController::class.'::show(): #[TypefinderPage] declares prop "b" for "Drift/Show" but it is never passed',
            DriftingPageController::class.'::edit() passes prop "roles" to Inertia page "Drift/Edit" but #[TypefinderPage] does not declare it',
            DriftingPageController::class.'::index(): prop "stats" of "Drift/Index" is optional or deferred in code but required in #[TypefinderPage] (add it to optional?)',
        ];
    }

    public function test_full_run_reports_each_drift_warning_once(): void
    {
        $regenResult = app(Generator::class)->generateFull();
        $drift = $this->driftWarnings($regenResult->warnings);

        foreach ([...$this->resourceDrift(), ...$this->modelDrift(), ...$this->pageDrift()] as $expected) {
            $this->assertSame(1, count(array_keys($drift, $expected, true)), $expected."\n".implode("\n", $drift));
        }

        $this->assertCount(14, $drift, implode("\n", $drift));

        foreach (['PostResource', 'AdminUserResource', 'UserResource', 'PostSummaryResource', 'ProductResource', 'ProductCardResource', 'NotComposableResource', 'UserController', 'DashboardController', 'PostController', 'PlainController'] as $clean) {
            foreach ($drift as $warning) {
                $this->assertStringNotContainsString('\\'.$clean.'::', $warning);
                $this->assertStringNotContainsString('\\'.$clean.':', $warning);
            }
        }
    }

    public function test_page_drift_needs_inertia_enabled(): void
    {
        config(['typefinder.inertia.enabled' => false]);

        $drift = $this->driftWarnings(app(Generator::class)->generateFull()->warnings);

        $this->assertCount(10, $drift, implode("\n", $drift));
    }

    public function test_only_run_on_a_resource_reports_its_warnings_once(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $regenResult = $generator->generatePaths([workbench_path('app/Http/Resources/DriftingResource.php')]);

        $this->assertSame(
            [$this->resourceDrift()[0], $this->resourceDrift()[1]],
            $this->driftWarnings($regenResult->warnings),
        );
    }

    public function test_only_run_on_a_model_typed_resource_reports_its_drift_once_when_the_cache_is_warm(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $regenResult = $generator->generatePaths([workbench_path('app/Http/Resources/CategoryResource.php')]);

        $this->assertSame([$this->modelDrift()[0]], $this->driftWarnings($regenResult->warnings));
    }

    public function test_only_run_on_a_clean_model_typed_resource_reports_no_drift(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $regenResult = $generator->generatePaths([workbench_path('app/Http/Resources/ProductCardResource.php')]);

        $this->assertSame([], $this->driftWarnings($regenResult->warnings));
    }

    public function test_only_run_with_a_cold_cache_reports_drift_like_a_full_run(): void
    {
        $generator = app(Generator::class);
        $full = $this->driftWarnings($generator->generateFull()->warnings);
        // Without the cache nothing is known about the other types, so the run extracts everything.
        @unlink(storage_path('framework/cache/typefinder/extractions.json'));

        $regenResult = $generator->generatePaths([workbench_path('app/Http/Resources/CategoryResource.php')]);

        $this->assertSame($full, $this->driftWarnings($regenResult->warnings));
        $this->assertContains($this->modelDrift()[0], $this->driftWarnings($regenResult->warnings));
    }

    public function test_only_run_on_a_controller_reports_its_page_warnings_once(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $regenResult = $generator->generatePaths([workbench_path('app/Http/Controllers/DriftingPageController.php')]);

        $this->assertEqualsCanonicalizing($this->pageDrift(), $this->driftWarnings($regenResult->warnings));
        $this->assertCount(4, $this->driftWarnings($regenResult->warnings));
    }

    public function test_only_run_on_a_clean_controller_reports_no_drift(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $regenResult = $generator->generatePaths([workbench_path('app/Http/Controllers/PostController.php')]);

        $this->assertSame([], $this->driftWarnings($regenResult->warnings));
    }

    public function test_repeated_attributes_and_shared_props_report_no_drift(): void
    {
        $generator = app(Generator::class);
        $full = $this->driftWarnings($generator->generateFull()->warnings);

        foreach ($full as $warning) {
            $this->assertStringNotContainsString('InSyncPageController', $warning);
        }

        $regenResult = $generator->generatePaths([workbench_path('app/Http/Controllers/InSyncPageController.php')]);

        $this->assertSame([], $this->driftWarnings($regenResult->warnings));
    }

    public function test_a_failing_drift_check_does_not_abort_generation(): void
    {
        $id = str_replace('.', '', uniqid('', true));
        $namespace = 'Scratch\\Boom'.$id;
        $this->scratch = sys_get_temp_dir().'/typefinder-boom-'.$id;
        File::ensureDirectoryExists($this->scratch.'/Resources');
        File::ensureDirectoryExists($this->scratch.'/Controllers');

        // Reading `Boom::make($this->whenLoaded(…))` asks the autoloader
        // whether Boom is a resource; this one throws.
        $this->autoloader = static function (string $class) use ($namespace): void {
            if ($class === $namespace.'\\Boom') {
                throw new RuntimeException('autoloader failure');
            }
        };
        spl_autoload_register($this->autoloader);

        $resource = $this->scratch.'/Resources/BoomResource.php';
        File::put($resource, <<<PHP
            <?php
            namespace {$namespace};

            #[\\Pentacore\\Typefinder\\Attributes\\TypefinderResource(shape: ['id' => 'number'])]
            class BoomResource extends \\Illuminate\\Http\\Resources\\Json\\JsonResource
            {
                public function toArray(\\Illuminate\\Http\\Request \$request): array
                {
                    return ['id' => 1, 'x' => Boom::make(\$this->whenLoaded('x'))];
                }
            }
            PHP);
        $controller = $this->scratch.'/Controllers/BoomController.php';
        File::put($controller, <<<PHP
            <?php
            namespace {$namespace};

            class BoomController
            {
                #[\\Pentacore\\Typefinder\\Attributes\\TypefinderPage('Boom/Show', ['a' => 'number'])]
                public function show()
                {
                    return \\Inertia\\Inertia::render('Boom/Show', ['a' => 1, 'x' => Boom::make(\$this->whenLoaded('x'))]);
                }
            }
            PHP);
        require (string) realpath($resource);
        require (string) realpath($controller);

        config([
            'typefinder.resources.paths' => [$this->scratch.'/Resources'],
            'typefinder.inertia.paths' => [$this->scratch.'/Controllers'],
        ]);

        $generator = app(Generator::class);
        $full = $generator->generateFull();

        $this->assertSame([], $this->driftWarnings($full->warnings));
        $this->assertFileExists($this->output.'/resources/BoomResource.d.ts');
        $this->assertStringContainsString("'Boom/Show'", File::get($this->output.'/pages.d.ts'));

        $only = $generator->generatePaths([$resource, $controller]);

        $this->assertSame([], $this->driftWarnings($only->warnings));
    }

    public function test_strict_fails_on_drift(): void
    {
        $this->artisan('typefinder:generate', ['--strict' => true])
            ->expectsOutputToContain('[typefinder] warning: '.DriftingResource::class.'::toArray() returns key "name"')
            ->assertFailed();
    }
}
