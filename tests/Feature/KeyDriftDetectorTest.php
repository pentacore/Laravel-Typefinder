<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DriftingPageController;
use App\Http\Controllers\InSyncPageController;
use App\Http\Controllers\PlainController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\ComposedLiteralResource;
use App\Http\Resources\ComposedParentResource;
use App\Http\Resources\ComposedResource;
use App\Http\Resources\ConditionalKeysResource;
use App\Http\Resources\DoubleDeclaredResource;
use App\Http\Resources\DriftingResource;
use App\Http\Resources\InSyncResource;
use App\Http\Resources\OpenReturnResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Analysis\InertiaSharedProps;
use Pentacore\Typefinder\Analysis\KeyDriftDetector;
use Pentacore\Typefinder\Extractors\ControllerExtractor;
use Pentacore\Typefinder\Extractors\ResourceExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

final class KeyDriftDetectorTest extends TestCase
{
    private KeyDriftDetector $keyDriftDetector;

    private ResourceExtractor $resourceExtractor;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'typefinder.models.paths' => [workbench_path('app/Models')],
            'typefinder.enums.paths' => [workbench_path('app/Enums')],
            'typefinder.resources.paths' => [workbench_path('app/Http/Resources')],
        ]);
        $this->keyDriftDetector = new KeyDriftDetector;
        $this->resourceExtractor = new ResourceExtractor;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $tempDir) {
            File::deleteDirectory($tempDir);
        }

        parent::tearDown();
    }

    public function test_drifting_resource_reports_undeclared_and_missing_keys(): void
    {
        $this->assertSame([
            DriftingResource::class.'::toArray() returns key "name" not declared in #[TypefinderResource] shape',
            DriftingResource::class.'::toArray(): #[TypefinderResource] shape declares "title" but toArray() never returns it',
        ], $this->checkResource(DriftingResource::class));
    }

    public function test_open_return_reports_only_undeclared_keys(): void
    {
        $this->assertSame([
            OpenReturnResource::class.'::toArray() returns key "extra" not declared in #[TypefinderResource] shape',
        ], $this->checkResource(OpenReturnResource::class));
    }

    public function test_conditional_keys_against_a_phpdoc_shape(): void
    {
        $this->assertSame([
            ConditionalKeysResource::class.'::toArray() returns key "stats" not declared in PHPDoc @return shape',
            ConditionalKeysResource::class.'::toArray(): PHPDoc @return shape declares "name" as required but toArray() does not always return it (make it optional?)',
            ConditionalKeysResource::class.'::toArray(): "posts" is conditional in code but required in PHPDoc @return shape',
            ConditionalKeysResource::class.'::toArray(): "meta" is conditional in code but required in PHPDoc @return shape',
        ], $this->checkResource(ConditionalKeysResource::class));
    }

    public function test_in_sync_resource_ignores_nested_closure_returns(): void
    {
        $this->assertSame([], $this->checkResource(InSyncResource::class));
    }

    public function test_attribute_and_phpdoc_shapes_that_disagree(): void
    {
        $this->assertSame([
            'App\Http\Resources\DoubleDeclaredResource: #[TypefinderResource] shape and PHPDoc @return shape disagree (only in attribute: {title, status}; only in PHPDoc: {name})',
        ], $this->checkResource(DoubleDeclaredResource::class));
    }

    public function test_existing_workbench_resources_have_no_drift(): void
    {
        $this->assertSame([], $this->checkResource(PostResource::class));
        $this->assertSame([], $this->checkResource(AdminUserResource::class));
        $this->assertSame([], $this->checkResource(UserResource::class));
    }

    public function test_composed_resources_have_no_drift(): void
    {
        foreach ([ComposedResource::class, ComposedParentResource::class, ComposedLiteralResource::class] as $class) {
            $extraction = $this->resourceExtractor->extract($class);
            $this->assertSame('phpdoc', $extraction['shape']['kind'] ?? null, $class);
            $this->assertSame([], $this->keyDriftDetector->checkResource($extraction), $class);
        }
    }

    public function test_composed_shape_with_conditional_literals_and_several_returns_has_no_drift(): void
    {
        $class = $this->loadClass(<<<'PHP'
            class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    if ($this->resource === null) {
                        return ['id' => null];
                    }

                    return [...$this->base(), 'posts' => $this->whenLoaded('posts'), 'name' => $this->name];
                }

                /** @return array{id: int} */
                private function base(): array
                {
                    return ['id' => $this->id];
                }
            }
            PHP);

        $extraction = $this->resourceExtractor->extract($class);
        $this->assertSame('phpdoc', $extraction['shape']['kind'] ?? null);
        $this->assertSame([], $this->keyDriftDetector->checkResource($extraction));
    }

    public function test_disagreement_compares_only_a_documented_phpdoc_shape(): void
    {
        // The stub doc is no shape; the body composes, but a composed shape
        // is not "the PHPDoc @return shape" the attribute is compared with.
        $class = $this->loadClass(<<<'PHP'
            #[\Pentacore\Typefinder\Attributes\TypefinderResource(shape: ['id' => 'number'])]
            class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                /** @return array<string, mixed> */
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    return ['id' => 1, ...$this->base()];
                }

                /** @return array{name: string} */
                private function base(): array
                {
                    return ['name' => 'n'];
                }
            }
            PHP);

        $this->assertSame([], $this->checkResource($class));
    }

    public function test_only_the_drift_fixtures_report_drift_in_the_workbench(): void
    {
        $drifting = [];

        foreach ($this->resourceExtractor->extractFromDirectory(workbench_path('app/Http/Resources')) as $extraction) {
            if ($this->keyDriftDetector->checkResource($extraction) !== []) {
                $drifting[] = $extraction['fqcn'];
            }
        }

        $this->assertSame([
            ConditionalKeysResource::class,
            DoubleDeclaredResource::class,
            DriftingResource::class,
            OpenReturnResource::class,
        ], $drifting);
    }

    public function test_model_kind_is_not_checked(): void
    {
        $this->assertSame([], $this->keyDriftDetector->checkResource([
            'name' => 'DriftingResource',
            'fqcn' => DriftingResource::class,
            'shape' => ['kind' => 'model', 'model' => User::class, 'omit' => [], 'extend' => []],
        ]));
    }

    public function test_disagreement_omits_an_empty_side(): void
    {
        $class = $this->loadClass(<<<'PHP'
            #[\Pentacore\Typefinder\Attributes\TypefinderResource(shape: ['id' => 'number', 'title' => 'string'])]
            class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                /**
                 * @return array{id: int}
                 */
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    return parent::toArray($request);
                }
            }
            PHP);

        $this->assertSame([
            $class.': #[TypefinderResource] shape and PHPDoc @return shape disagree (only in attribute: {title})',
        ], $this->checkResource($class));
    }

    public function test_phpdoc_return_that_is_not_a_shape_is_not_compared_with_the_attribute(): void
    {
        $class = $this->loadClass(<<<'PHP'
            #[\Pentacore\Typefinder\Attributes\TypefinderResource(shape: ['id' => 'number'])]
            class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                /**
                 * @return array<string, mixed>
                 */
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    return ['id' => 1, 'extra' => 2];
                }
            }
            PHP);

        $this->assertSame([
            $class.'::toArray() returns key "extra" not declared in #[TypefinderResource] shape',
        ], $this->checkResource($class));
    }

    public function test_attribute_and_phpdoc_shapes_with_the_same_keys_agree(): void
    {
        $class = $this->loadClass(<<<'PHP'
            #[\Pentacore\Typefinder\Attributes\TypefinderResource(shape: ['id' => 'number', 'title' => 'string'])]
            class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                /**
                 * @return array{title: string, id: int}
                 */
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    return ['id' => 1, 'title' => 'x', 'extra' => 2];
                }
            }
            PHP);

        $this->assertSame([
            $class.'::toArray() returns key "extra" not declared in #[TypefinderResource] shape',
        ], $this->checkResource($class));
    }

    public function test_attribute_key_with_trailing_question_mark_is_optional(): void
    {
        $class = $this->loadClass(<<<'PHP'
            #[\Pentacore\Typefinder\Attributes\TypefinderResource(shape: ['id' => 'number', 'note?' => 'string'])]
            class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    if ($request->boolean('x')) {
                        return ['id' => 1, 'note' => 'n'];
                    }

                    return ['id' => 2];
                }
            }
            PHP);

        $this->assertSame([], $this->checkResource($class));
    }

    public function test_empty_guard_return_is_not_a_return_without_the_key(): void
    {
        $class = $this->loadClass(<<<'PHP'
            #[\Pentacore\Typefinder\Attributes\TypefinderResource(shape: ['id' => 'number', 'name' => 'string', 'title' => 'string', 'gone' => 'string'])]
            class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    if ($this->resource === null) {
                        return [];
                    }

                    if ($request->boolean('short')) {
                        return ['id' => 1, 'name' => 'n'];
                    }

                    return ['id' => 1, 'name' => 'n', 'title' => 't'];
                }
            }
            PHP);

        $this->assertSame([
            $class.'::toArray(): #[TypefinderResource] shape declares "title" as required but toArray() does not always return it (make it optional?)',
            $class.'::toArray(): #[TypefinderResource] shape declares "gone" but toArray() never returns it',
        ], $this->checkResource($class));
    }

    public function test_only_empty_returns_still_never_return_a_key(): void
    {
        $class = $this->loadClass(<<<'PHP'
            #[\Pentacore\Typefinder\Attributes\TypefinderResource(shape: ['id' => 'number'])]
            class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertSame([
            $class.'::toArray(): #[TypefinderResource] shape declares "id" but toArray() never returns it',
        ], $this->checkResource($class));
    }

    public function test_disagreement_ignores_an_inherited_phpdoc_shape(): void
    {
        $parent = $this->loadClass(<<<'PHP'
            abstract class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                /**
                 * @return array{id: int, name: string}
                 */
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    return ['id' => 1, 'name' => 'n'];
                }
            }
            PHP);
        $child = $this->loadClass(<<<PHP
            #[\\Pentacore\\Typefinder\\Attributes\\TypefinderResource(shape: ['id' => 'number'])]
            class {CLASS} extends \\{$parent}
            {
                public function toArray(\\Illuminate\\Http\\Request \$request): array
                {
                    return ['id' => 1];
                }
            }
            PHP);

        $this->assertSame([], $this->checkResource($child));
    }

    public function test_disagreement_with_an_open_phpdoc_shape_lists_only_phpdoc_keys(): void
    {
        $class = $this->loadClass(<<<'PHP'
            #[\Pentacore\Typefinder\Attributes\TypefinderResource(shape: ['id' => 'number', 'title' => 'string'])]
            class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                /**
                 * @return array{id: int, name: string, ...}
                 */
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    return parent::toArray($request);
                }
            }
            PHP);

        $this->assertSame([
            $class.': #[TypefinderResource] shape and PHPDoc @return shape disagree (only in PHPDoc: {name})',
        ], $this->checkResource($class));
    }

    public function test_key_conditional_in_one_return_and_unconditional_in_another_is_not_reported(): void
    {
        $class = $this->loadClass(<<<'PHP'
            #[\Pentacore\Typefinder\Attributes\TypefinderResource(shape: ['id' => 'number', 'posts' => 'unknown[]'])]
            class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    if ($request->boolean('x')) {
                        return ['id' => 1, 'posts' => $this->whenLoaded('posts')];
                    }

                    return ['id' => 2, 'posts' => []];
                }
            }
            PHP);

        $this->assertSame([], $this->checkResource($class));
    }

    public function test_unless_without_a_default_on_a_required_key_is_conditional_but_required(): void
    {
        $class = $this->loadClass(<<<'PHP'
            class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                /**
                 * @return array{id: int, secret: string, hint: string}
                 */
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    return [
                        'id' => 1,
                        'secret' => $this->unless($request->boolean('guest'), 's'),
                        'hint' => $this->unless($request->boolean('guest'), 'h', ''),
                    ];
                }
            }
            PHP);

        $this->assertSame([
            $class.'::toArray(): "secret" is conditional in code but required in PHPDoc @return shape',
        ], $this->checkResource($class));
    }

    public function test_optional_declared_keys_are_never_reported_missing(): void
    {
        $class = $this->loadClass(<<<'PHP'
            class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                /**
                 * @return array{id: int, note?: string, tags?: list<string>}
                 */
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    if ($request->boolean('x')) {
                        return ['id' => 1, 'tags' => $this->when(true, [])];
                    }

                    return ['id' => 2];
                }
            }
            PHP);

        $this->assertSame([], $this->checkResource($class));
    }

    public function test_open_phpdoc_shape_does_not_report_undeclared_keys(): void
    {
        $class = $this->loadClass(<<<'PHP'
            class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                /**
                 * @return array{id: int, title: string, ...}
                 */
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    return ['id' => 1, 'anything' => 2];
                }
            }
            PHP);

        $this->assertSame([
            $class.'::toArray(): PHPDoc @return shape declares "title" but toArray() never returns it',
        ], $this->checkResource($class));
    }

    public function test_missing_key_is_not_reported_when_a_return_is_open(): void
    {
        $class = $this->loadClass(<<<'PHP'
            #[\Pentacore\Typefinder\Attributes\TypefinderResource(shape: ['id' => 'number', 'title' => 'string', 'body' => 'string'])]
            class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    if ($request->boolean('x')) {
                        return ['id' => 1, 'title' => 'a'];
                    }

                    return ['id' => 2, ...$this->extra()];
                }
            }
            PHP);

        $this->assertSame([], $this->checkResource($class));
    }

    public function test_each_warning_is_emitted_once(): void
    {
        $class = $this->loadClass(<<<'PHP'
            #[\Pentacore\Typefinder\Attributes\TypefinderResource(shape: ['id' => 'number'])]
            class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    if ($request->boolean('x')) {
                        return ['id' => 1, 'extra' => 1];
                    }

                    return ['id' => 2, 'extra' => 2, 'more' => 3];
                }
            }
            PHP);

        $this->assertSame([
            $class.'::toArray() returns key "extra" not declared in #[TypefinderResource] shape',
            $class.'::toArray() returns key "more" not declared in #[TypefinderResource] shape',
        ], $this->checkResource($class));
    }

    public function test_inherited_to_array_is_not_checked(): void
    {
        $parent = $this->loadClass(<<<'PHP'
            abstract class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    return ['unrelated' => 1];
                }
            }
            PHP);
        $child = $this->loadClass(<<<PHP
            #[\\Pentacore\\Typefinder\\Attributes\\TypefinderResource(shape: ['id' => 'number'])]
            class {CLASS} extends \\{$parent} {}
            PHP);

        $this->assertSame([], $this->checkResource($child));
    }

    public function test_file_that_no_longer_parses_reports_nothing(): void
    {
        $class = $this->loadClass(<<<'PHP'
            #[\Pentacore\Typefinder\Attributes\TypefinderResource(shape: ['id' => 'number'])]
            class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    return ['undeclared' => 1];
                }
            }
            PHP);
        $extraction = $this->resourceExtractor->extract($class);
        $this->assertNotNull($extraction);

        // The class stays loaded; the file on disk is now broken.
        File::put((string) new \ReflectionClass($class)->getFileName(), "<?php\nclass {");

        $this->assertSame([], $this->keyDriftDetector->checkResource($extraction));
    }

    public function test_page_reports_undeclared_and_never_passed_props(): void
    {
        $this->assertSame([
            DriftingPageController::class.'::show() passes prop "c" to Inertia page "Drift/Show" but #[TypefinderPage] does not declare it',
            DriftingPageController::class.'::show(): #[TypefinderPage] declares prop "b" for "Drift/Show" but it is never passed',
        ], $this->checkPage(DriftingPageController::class, 'show'));
    }

    public function test_page_reads_compact_props(): void
    {
        $this->assertSame([
            DriftingPageController::class.'::edit() passes prop "roles" to Inertia page "Drift/Edit" but #[TypefinderPage] does not declare it',
        ], $this->checkPage(DriftingPageController::class, 'edit'));
    }

    public function test_deferred_prop_declared_required(): void
    {
        $this->assertSame([
            DriftingPageController::class.'::index(): prop "stats" of "Drift/Index" is optional or deferred in code but required in #[TypefinderPage] (add it to optional?)',
        ], $this->checkPage(DriftingPageController::class, 'index'));
    }

    public function test_render_in_a_helper_is_not_checked(): void
    {
        $this->assertSame([], $this->checkPage(DriftingPageController::class, 'helper'));
    }

    public function test_existing_workbench_pages_have_no_drift(): void
    {
        $controllerExtractor = new ControllerExtractor;
        $pages = [
            ...$controllerExtractor->extract(UserController::class),
            ...$controllerExtractor->extract(DashboardController::class),
            ...$controllerExtractor->extract(PostController::class),
            ...$controllerExtractor->extract(PlainController::class),
        ];

        $this->assertCount(5, $pages);

        foreach ($pages as $page) {
            $this->assertSame([], $this->keyDriftDetector->checkPage($page), $page['source']);
        }
    }

    public function test_in_sync_page_fixtures_have_no_drift(): void
    {
        $pages = new ControllerExtractor()->extract(InSyncPageController::class);
        $this->assertCount(4, $pages);

        $keyDriftDetector = new KeyDriftDetector(sharedPageProps: InertiaSharedProps::fromDirectory(workbench_path('app/Http/Middleware')));

        $this->assertSame([], $keyDriftDetector->checkPages($pages));
    }

    public function test_repeated_page_attributes_on_one_action_merge_before_the_check(): void
    {
        $class = $this->loadClass(<<<'PHP'
            use Inertia\Inertia;
            use Pentacore\Typefinder\Attributes\TypefinderPage;

            class {CLASS}
            {
                #[TypefinderPage('Form', ['title' => 'string', 'body' => 'string'])]
                #[TypefinderPage('Form', ['title' => 'string', 'body' => 'string', 'post' => 'number', 'tags' => 'string[]'], optional: ['body'])]
                #[TypefinderPage('Other', ['a' => 'number'])]
                public function form(?int $post)
                {
                    if ($post === null) {
                        return Inertia::render('Form', ['title' => 'New', 'extra' => 1]);
                    }

                    Inertia::render('Other', ['a' => 1]);

                    return Inertia::render('Form', ['body' => '', 'post' => $post]);
                }
            }
            PHP, 'Controller');

        $this->assertSame([
            $class.'::form() passes prop "extra" to Inertia page "Form" but #[TypefinderPage] does not declare it',
            $class.'::form(): #[TypefinderPage] declares prop "title" for "Form" as required but it is not always passed (add it to optional?)',
        ], $this->keyDriftDetector->checkPages(new ControllerExtractor()->extract($class)));
    }

    public function test_pages_of_different_actions_are_checked_apart(): void
    {
        $class = $this->loadClass(<<<'PHP'
            use Inertia\Inertia;

            class {CLASS}
            {
                public function create()
                {
                    return Inertia::render('Form', ['title' => 'New']);
                }

                public function edit()
                {
                    return Inertia::render('Form', ['title' => 'Edit', 'post' => 1]);
                }
            }
            PHP, 'Controller');

        $this->assertSame([
            $class.'::create(): #[TypefinderPage] declares prop "post" for "Form" but it is never passed',
        ], $this->keyDriftDetector->checkPages([
            $this->page($class, 'create', 'Form', ['title' => 'string', 'post' => 'number']),
            $this->page($class, 'edit', 'Form', ['title' => 'string', 'post' => 'number']),
        ]));
    }

    public function test_errors_prop_is_never_reported_missing(): void
    {
        $class = $this->loadClass(<<<'PHP'
            use Inertia\Inertia;

            class {CLASS}
            {
                public function show(bool $flag)
                {
                    if ($flag) {
                        return Inertia::render('X', ['a' => 1, 'errors' => []]);
                    }

                    return Inertia::render('X', ['a' => 1]);
                }

                public function bare()
                {
                    return Inertia::render('X', ['a' => 1]);
                }
            }
            PHP, 'Controller');

        $this->assertSame([], $this->keyDriftDetector->checkPage($this->page($class, 'show', 'X', ['a' => 'number', 'errors' => 'object'])));
        $this->assertSame([], $this->keyDriftDetector->checkPage($this->page($class, 'bare', 'X', ['a' => 'number', 'errors' => 'object'])));
    }

    public function test_shared_page_props_are_never_reported_missing_but_still_undeclared(): void
    {
        $class = $this->loadClass(<<<'PHP'
            use Inertia\Inertia;

            class {CLASS}
            {
                public function show(bool $flag)
                {
                    if ($flag) {
                        return Inertia::render('X', ['a' => 1, 'auth' => 1]);
                    }

                    return Inertia::render('X', ['a' => 1, 'flash' => 1]);
                }
            }
            PHP, 'Controller');

        $keyDriftDetector = new KeyDriftDetector(sharedPageProps: ['auth', 'flash']);

        $this->assertSame([], $keyDriftDetector->checkPage($this->page($class, 'show', 'X', ['a' => 'number', 'auth' => 'object', 'flash' => 'object'])));
        $this->assertSame([
            $class.'::show() passes prop "flash" to Inertia page "X" but #[TypefinderPage] does not declare it',
        ], $keyDriftDetector->checkPage($this->page($class, 'show', 'X', ['a' => 'number', 'auth' => 'object', 'gone' => 'number'], ['gone'])));
    }

    public function test_inertia_share_in_the_action_opens_its_renders(): void
    {
        $class = $this->loadClass(<<<'PHP'
            use Inertia\Inertia;

            class {CLASS}
            {
                public function facade()
                {
                    Inertia::share(['notice' => 'Saved']);

                    return Inertia::render('X', ['a' => 1, 'x' => 2]);
                }

                public function helper()
                {
                    inertia()->share('notice', 'Saved');

                    return Inertia::render('X', ['a' => 1]);
                }

                public function other()
                {
                    $this->share('notice', 'Saved');

                    return Inertia::render('X', ['a' => 1]);
                }
            }
            PHP, 'Controller');

        $declared = ['a' => 'number', 'notice' => 'string'];

        $this->assertSame([
            $class.'::facade() passes prop "x" to Inertia page "X" but #[TypefinderPage] does not declare it',
        ], $this->keyDriftDetector->checkPage($this->page($class, 'facade', 'X', $declared)));
        $this->assertSame([], $this->keyDriftDetector->checkPage($this->page($class, 'helper', 'X', $declared)));
        $this->assertSame([
            $class.'::other(): #[TypefinderPage] declares prop "notice" for "X" but it is never passed',
        ], $this->keyDriftDetector->checkPage($this->page($class, 'other', 'X', $declared)));
    }

    public function test_shared_props_are_read_from_inertia_middleware(): void
    {
        $this->assertSame(['auth', 'flash'], InertiaSharedProps::fromDirectory(workbench_path('app/Http/Middleware')));
        $this->assertFalse(class_exists(HandleInertiaRequests::class, false), 'the middleware fixture must not be loaded');
    }

    public function test_shared_props_middleware_variants(): void
    {
        $dir = $this->tempDir();
        File::ensureDirectoryExists($dir.'/Nested');
        File::put($dir.'/Spread.php', <<<'PHP'
            <?php
            namespace Scratch\Shared;

            class Spread extends \Inertia\Middleware
            {
                public function share($request): array
                {
                    if ($request->ajax()) {
                        return [...parent::share($request), 'ajax' => true];
                    }

                    return [...parent::share($request), 'auth' => 1, $request->key() => 2];
                }
            }
            PHP);
        File::put($dir.'/Nested/Imported.php', <<<'PHP'
            <?php
            namespace Scratch\Shared;

            use Inertia\Middleware as InertiaMiddleware;

            class Imported extends InertiaMiddleware
            {
                public function share($request): array
                {
                    return ['locale' => 'en', 'auth' => 1];
                }
            }
            PHP);
        File::put($dir.'/NotInertia.php', <<<'PHP'
            <?php
            namespace Scratch\Shared;

            class NotInertia extends Middleware
            {
                public function share($request): array
                {
                    return ['unrelated' => 1];
                }
            }
            PHP);
        File::put($dir.'/Broken.php', "<?php\nclass Broken extends \\Inertia\\Middleware {");
        File::put($dir.'/notes.txt', 'not php');

        $this->assertEqualsCanonicalizing(['ajax', 'auth', 'locale'], InertiaSharedProps::fromDirectory($dir));
        $this->assertSame([], InertiaSharedProps::fromDirectory($dir.'/missing'));
    }

    public function test_global_inertia_alias_is_matched(): void
    {
        $class = $this->loadClass(<<<'PHP'
            use Inertia;

            class {CLASS}
            {
                public function imported()
                {
                    return Inertia::render('Alias/Show', ['a' => 1, 'extra' => 2]);
                }

                public function qualified()
                {
                    return \Inertia::render('Alias/Show', ['a' => 1, 'other' => 2]);
                }
            }
            PHP, 'Controller');

        $this->assertSame([
            $class.'::imported() passes prop "extra" to Inertia page "Alias/Show" but #[TypefinderPage] does not declare it',
        ], $this->keyDriftDetector->checkPage($this->page($class, 'imported', 'Alias/Show', ['a' => 'number'])));
        $this->assertSame([
            $class.'::qualified() passes prop "other" to Inertia page "Alias/Show" but #[TypefinderPage] does not declare it',
        ], $this->keyDriftDetector->checkPage($this->page($class, 'qualified', 'Alias/Show', ['a' => 'number'])));
    }

    public function test_unqualified_unimported_inertia_in_a_namespace_is_not_matched(): void
    {
        $class = $this->loadClass(<<<'PHP'
            class {CLASS}
            {
                public function show()
                {
                    return Inertia::render('NoImport/Show', ['zzz' => 1]);
                }
            }
            PHP, 'Controller');

        $this->assertSame([], $this->keyDriftDetector->checkPage($this->page($class, 'show', 'NoImport/Show', ['a' => 'number'])));
    }

    public function test_inertia_helper_and_chained_with_calls(): void
    {
        $class = $this->loadClass(<<<'PHP'
            use Inertia\Inertia;

            class {CLASS}
            {
                public function show()
                {
                    return INERTIA('Helper/Show', ['a' => 1])
                        ->with(['b' => 2, 'x' => 3])
                        ->with('c', Inertia::lazy(fn () => 4))
                        ->with('y', 5);
                }
            }
            PHP, 'Controller');

        $this->assertSame([
            $class.'::show() passes prop "x" to Inertia page "Helper/Show" but #[TypefinderPage] does not declare it',
            $class.'::show() passes prop "y" to Inertia page "Helper/Show" but #[TypefinderPage] does not declare it',
            $class.'::show(): prop "c" of "Helper/Show" is optional or deferred in code but required in #[TypefinderPage] (add it to optional?)',
        ], $this->keyDriftDetector->checkPage($this->page($class, 'show', 'Helper/Show', ['a' => 'number', 'b' => 'number', 'c' => 'number'])));
    }

    public function test_props_missing_from_one_of_several_renders(): void
    {
        $class = $this->loadClass(<<<'PHP'
            use Inertia\Inertia;

            class {CLASS}
            {
                public function show(bool $full)
                {
                    $unused = fn () => Inertia::render('Multi/Show', ['nested' => 1]);

                    if ($full) {
                        return Inertia::render('Multi/Show', ['a' => 1, 'b' => 2, 'opt' => 3]);
                    }

                    Inertia::render('Other/Page', ['other' => 1]);

                    return Inertia::render(props: ['a' => 1], component: 'Multi/Show');
                }
            }
            PHP, 'Controller');

        $this->assertSame([
            $class.'::show(): #[TypefinderPage] declares prop "b" for "Multi/Show" as required but it is not always passed (add it to optional?)',
        ], $this->keyDriftDetector->checkPage($this->page($class, 'show', 'Multi/Show', ['a' => 'number', 'b' => 'number', 'opt' => 'number', 'never' => 'number'], ['opt', 'never'])));
    }

    public function test_render_without_props_passes_nothing(): void
    {
        $class = $this->loadClass(<<<'PHP'
            use Inertia\Inertia;

            class {CLASS}
            {
                public function show()
                {
                    return Inertia::render('Bare/Show');
                }
            }
            PHP, 'Controller');

        $this->assertSame([
            $class.'::show(): #[TypefinderPage] declares prop "a" for "Bare/Show" but it is never passed',
        ], $this->keyDriftDetector->checkPage($this->page($class, 'show', 'Bare/Show', ['a' => 'number'])));
    }

    public function test_non_literal_props_report_only_literal_extras(): void
    {
        $class = $this->loadClass(<<<'PHP'
            use Inertia\Inertia;

            class {CLASS}
            {
                public function show(array $props)
                {
                    return Inertia::render('Open/Show', $props)->with('extra', 1);
                }

                public function dynamic(string $name)
                {
                    return Inertia::render('Open/Show', compact($name));
                }
            }
            PHP, 'Controller');

        $this->assertSame([
            $class.'::show() passes prop "extra" to Inertia page "Open/Show" but #[TypefinderPage] does not declare it',
        ], $this->keyDriftDetector->checkPage($this->page($class, 'show', 'Open/Show', ['a' => 'number'])));
        $this->assertSame([], $this->keyDriftDetector->checkPage($this->page($class, 'dynamic', 'Open/Show', ['a' => 'number'])));
    }

    /**
     * An open part of the same return may supply the key unconditionally.
     *
     * @return iterable<string, array{string}>
     */
    public static function conditionalNextToOpenPartProvider(): iterable
    {
        yield 'spread after' => ["return ['id' => 1, 'posts' => \$this->whenLoaded('posts'), ...\$this->extra()];"];
        yield 'array_merge' => ["return array_merge(['id' => 1, 'posts' => \$this->whenLoaded('posts')], \$this->extra());"];
        yield 'plus' => ["return \$this->extra() + ['id' => 1, 'posts' => \$this->whenLoaded('posts')];"];
        yield 'mergeWhen' => ["return ['id' => 1, \$this->mergeWhen(true, ['posts' => []]), ...\$this->extra()];"];
    }

    #[DataProvider('conditionalNextToOpenPartProvider')]
    public function test_conditional_key_in_an_open_return_is_not_reported(string $return): void
    {
        $class = $this->loadClass(<<<PHP
            #[\\Pentacore\\Typefinder\\Attributes\\TypefinderResource(shape: ['id' => 'number', 'posts' => 'unknown[]'])]
            class {CLASS} extends \\Illuminate\\Http\\Resources\\Json\\JsonResource
            {
                public function toArray(\\Illuminate\\Http\\Request \$request): array
                {
                    {$return}
                }
            }
            PHP);

        $this->assertSame([], $this->checkResource($class));
    }

    public function test_optional_prop_in_open_props_is_not_reported(): void
    {
        $class = $this->loadClass(<<<'PHP'
            use Inertia\Inertia;

            class {CLASS}
            {
                public function show()
                {
                    return Inertia::render('X', ['b' => Inertia::optional(fn () => 1), ...$this->shared()]);
                }
            }
            PHP, 'Controller');

        $this->assertSame([], $this->keyDriftDetector->checkPage($this->page($class, 'show', 'X', ['b' => 'number'])));
    }

    public function test_render_modified_outside_its_with_chain_is_open(): void
    {
        $class = $this->loadClass(<<<'PHP'
            use Inertia\Inertia;

            class {CLASS}
            {
                public function assigned()
                {
                    $response = Inertia::render('X', ['a' => 1, 'extra' => 2]);

                    return $response->with('b', 2);
                }

                public function viewData()
                {
                    return Inertia::render('X', ['a' => 1])->withViewData(['title' => 'x'])->with('b', 2);
                }

                public function argument()
                {
                    return $this->wrap(Inertia::render('X', ['a' => 1]));
                }
            }
            PHP, 'Controller');

        $this->assertSame([
            $class.'::assigned() passes prop "extra" to Inertia page "X" but #[TypefinderPage] does not declare it',
        ], $this->keyDriftDetector->checkPage($this->page($class, 'assigned', 'X', ['a' => 'number', 'b' => 'number'])));
        $this->assertSame([], $this->keyDriftDetector->checkPage($this->page($class, 'viewData', 'X', ['a' => 'number', 'b' => 'number'])));
        $this->assertSame([], $this->keyDriftDetector->checkPage($this->page($class, 'argument', 'X', ['a' => 'number', 'b' => 'number'])));
    }

    public function test_render_via_a_helper_on_another_path_opens_the_direct_render(): void
    {
        $class = $this->loadClass(<<<'PHP'
            use Inertia\Inertia;

            class {CLASS}
            {
                public function form(?int $post)
                {
                    if ($post !== null) {
                        return $this->renderForm($post);
                    }

                    return Inertia::render('Form', ['title' => 'New', 'extra' => 1]);
                }

                private function renderForm(int $post)
                {
                    return Inertia::render('Form', ['title' => 'Edit', 'post' => $post]);
                }
            }
            PHP, 'Controller');

        $this->assertSame([
            $class.'::form() passes prop "extra" to Inertia page "Form" but #[TypefinderPage] does not declare it',
        ], $this->keyDriftDetector->checkPage($this->page($class, 'form', 'Form', ['title' => 'string', 'post' => 'number'])));
    }

    /**
     * Precision trade-off: any return that is not a direct render of the
     * component (a redirect, a bare `return;`, another page, a ternary arm)
     * might be a render made elsewhere, so missing props are not reported
     * for that action even when the other path plainly renders nothing.
     */
    public function test_any_other_return_suppresses_missing_prop_warnings(): void
    {
        $class = $this->loadClass(<<<'PHP'
            use Inertia\Inertia;

            class {CLASS}
            {
                public function redirects(bool $ok)
                {
                    if (! $ok) {
                        return redirect()->back();
                    }

                    return Inertia::render('X', ['a' => 1, 'x' => 2]);
                }

                public function bare(bool $ok)
                {
                    if (! $ok) {
                        return;
                    }

                    return Inertia::render('X', ['a' => 1]);
                }

                public function otherPage(bool $ok)
                {
                    return $ok ? Inertia::render('X', ['a' => 1]) : Inertia::render('Y', ['b' => 1]);
                }

                public function nested(bool $ok)
                {
                    $render = fn () => redirect()->back();

                    return Inertia::render('X', ['a' => 1]);
                }
            }
            PHP, 'Controller');

        $declared = ['a' => 'number', 'b' => 'number'];

        $this->assertSame([
            $class.'::redirects() passes prop "x" to Inertia page "X" but #[TypefinderPage] does not declare it',
        ], $this->keyDriftDetector->checkPage($this->page($class, 'redirects', 'X', $declared)));
        $this->assertSame([], $this->keyDriftDetector->checkPage($this->page($class, 'bare', 'X', $declared)));
        $this->assertSame([], $this->keyDriftDetector->checkPage($this->page($class, 'otherPage', 'X', $declared)));
        // A return inside a closure is not the action's own.
        $this->assertSame([
            $class.'::nested(): #[TypefinderPage] declares prop "b" for "X" but it is never passed',
        ], $this->keyDriftDetector->checkPage($this->page($class, 'nested', 'X', $declared)));
    }

    public function test_render_as_a_ternary_or_match_arm_of_a_return_is_closed(): void
    {
        $class = $this->loadClass(<<<'PHP'
            use Inertia\Inertia;

            class {CLASS}
            {
                public function ternary(bool $full)
                {
                    return $full ? Inertia::render('X', ['a' => 1, 'b' => 2]) : Inertia::render('X', ['a' => 1]);
                }

                public function matched(int $mode)
                {
                    return match ($mode) {
                        1 => Inertia::render('X', ['a' => 1, 'b' => 2])->with('c', 3),
                        default => Inertia::render('X', ['a' => 1]),
                    };
                }
            }
            PHP, 'Controller');

        $this->assertSame([
            $class.'::ternary(): #[TypefinderPage] declares prop "b" for "X" as required but it is not always passed (add it to optional?)',
        ], $this->keyDriftDetector->checkPage($this->page($class, 'ternary', 'X', ['a' => 'number', 'b' => 'number'])));
        $this->assertSame([
            $class.'::matched() passes prop "c" to Inertia page "X" but #[TypefinderPage] does not declare it',
            $class.'::matched(): #[TypefinderPage] declares prop "b" for "X" as required but it is not always passed (add it to optional?)',
        ], $this->keyDriftDetector->checkPage($this->page($class, 'matched', 'X', ['a' => 'number', 'b' => 'number'])));
    }

    public function test_spread_or_dynamic_arguments_open_the_render(): void
    {
        $class = $this->loadClass(<<<'PHP'
            use Inertia\Inertia;

            class {CLASS}
            {
                public function props(array $rest)
                {
                    return Inertia::render('Spread/Show', ...$rest)->with('extra', 1);
                }

                public function pairs(array $pairs)
                {
                    return Inertia::render('Spread/Show', ['a' => 1])->with(...$pairs)->with('extra', 1);
                }

                public function dynamicKey(string $key)
                {
                    return Inertia::render('Spread/Show', ['a' => 1])->with($key, 2)->with('extra', 1);
                }
            }
            PHP, 'Controller');

        // `b` may come from the spread or the dynamic key, so only the
        // literal extra is reported.
        foreach (['props', 'pairs', 'dynamicKey'] as $method) {
            $this->assertSame([
                $class.'::'.$method.'() passes prop "extra" to Inertia page "Spread/Show" but #[TypefinderPage] does not declare it',
            ], $this->keyDriftDetector->checkPage($this->page($class, $method, 'Spread/Show', ['a' => 'number', 'b' => 'number'])), $method);
        }
    }

    public function test_render_with_a_spread_component_is_not_matched(): void
    {
        $class = $this->loadClass(<<<'PHP'
            use Inertia\Inertia;

            class {CLASS}
            {
                public function show(array $args)
                {
                    return Inertia::render(...$args);
                }
            }
            PHP, 'Controller');

        $this->assertSame([], $this->keyDriftDetector->checkPage($this->page($class, 'show', 'Spread/Show', ['a' => 'number'])));
    }

    public function test_page_source_without_a_readable_body_reports_nothing(): void
    {
        $class = $this->loadClass(<<<'PHP'
            abstract class {CLASS}
            {
                abstract public function show();
            }
            PHP, 'Controller');

        $this->assertSame([], $this->keyDriftDetector->checkPage($this->page($class, 'show', 'X', ['a' => 'number'])));
        // An internal class has no source file.
        $this->assertSame([], $this->keyDriftDetector->checkPage($this->page(\ArrayObject::class, 'count', 'X', ['a' => 'number'])));
    }

    public function test_unloadable_page_source_reports_nothing(): void
    {
        $this->assertSame([], $this->keyDriftDetector->checkPage($this->page('App\Http\Controllers\MissingController', 'show', 'X', ['a' => 'number'])));
        $this->assertSame([], $this->keyDriftDetector->checkPage($this->page(DriftingPageController::class, 'missing', 'Drift/Show', ['a' => 'number'])));
    }

    /** @return list<string> */
    private function checkPage(string $class, string $method): array
    {
        $pages = array_values(array_filter(
            new ControllerExtractor()->extract($class),
            static fn (array $page): bool => $page['source'] === $class.'::'.$method,
        ));
        $this->assertCount(1, $pages);

        return $this->keyDriftDetector->checkPage($pages[0]);
    }

    /**
     * @param  array<string, string>  $props
     * @param  list<string>  $optional
     * @return array{component: string, props: array<string, string>, optional: list<string>, source: string}
     */
    private function page(string $class, string $method, string $component, array $props, array $optional = []): array
    {
        return ['component' => $component, 'props' => $props, 'optional' => $optional, 'source' => $class.'::'.$method];
    }

    /** @return list<string> */
    private function checkResource(string $class): array
    {
        $extraction = $this->resourceExtractor->extract($class);
        $this->assertNotNull($extraction, $class.' was not extracted');

        return $this->keyDriftDetector->checkResource($extraction);
    }

    /**
     * Write `$body` (with `{CLASS}` as the class name) to a temp file in its
     * own namespace, load it and return the FQCN. `$body` may start with
     * `use` imports.
     */
    private function loadClass(string $body, string $suffix = 'Resource'): string
    {
        $id = str_replace('.', '', uniqid('', true));
        $dir = $this->tempDir($id);

        $namespace = 'Scratch\Drift'.$id;
        $class = 'Drift'.$id.$suffix;
        $file = $dir.'/'.$class.'.php';
        File::put($file, "<?php\nnamespace {$namespace};\n\n".str_replace('{CLASS}', $class, $body)."\n");
        require (string) realpath($file);

        return $namespace.'\\'.$class;
    }

    private function tempDir(?string $id = null): string
    {
        $dir = sys_get_temp_dir().'/typefinder-drift-'.($id ?? str_replace('.', '', uniqid('', true)));
        File::ensureDirectoryExists($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }
}
