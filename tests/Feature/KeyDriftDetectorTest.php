<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\AdminUserResource;
use App\Http\Resources\ConditionalKeysResource;
use App\Http\Resources\DoubleDeclaredResource;
use App\Http\Resources\DriftingResource;
use App\Http\Resources\InSyncResource;
use App\Http\Resources\OpenReturnResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Analysis\KeyDriftDetector;
use Pentacore\Typefinder\Extractors\ResourceExtractor;
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
        $class = $this->resourceClass(<<<'PHP'
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

    public function test_key_conditional_in_one_return_and_unconditional_in_another_is_not_reported(): void
    {
        $class = $this->resourceClass(<<<'PHP'
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

    public function test_optional_declared_keys_are_never_reported_missing(): void
    {
        $class = $this->resourceClass(<<<'PHP'
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
        $class = $this->resourceClass(<<<'PHP'
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
        $class = $this->resourceClass(<<<'PHP'
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
        $class = $this->resourceClass(<<<'PHP'
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
        $parent = $this->resourceClass(<<<'PHP'
            abstract class {CLASS} extends \Illuminate\Http\Resources\Json\JsonResource
            {
                public function toArray(\Illuminate\Http\Request $request): array
                {
                    return ['unrelated' => 1];
                }
            }
            PHP);
        $child = $this->resourceClass(<<<PHP
            #[\\Pentacore\\Typefinder\\Attributes\\TypefinderResource(shape: ['id' => 'number'])]
            class {CLASS} extends \\{$parent} {}
            PHP);

        $this->assertSame([], $this->checkResource($child));
    }

    public function test_file_that_no_longer_parses_reports_nothing(): void
    {
        $class = $this->resourceClass(<<<'PHP'
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

    /** @return list<string> */
    private function checkResource(string $class): array
    {
        $extraction = $this->resourceExtractor->extract($class);
        $this->assertNotNull($extraction, $class.' was not extracted');

        return $this->keyDriftDetector->checkResource($extraction);
    }

    /**
     * Write `$body` (with `{CLASS}` as the class name) to a temp file in its
     * own namespace, load it and return the FQCN.
     */
    private function resourceClass(string $body): string
    {
        $id = str_replace('.', '', uniqid('', true));
        $dir = sys_get_temp_dir().'/typefinder-drift-'.$id;
        File::ensureDirectoryExists($dir);
        $this->tempDirs[] = $dir;

        $namespace = 'Scratch\Drift'.$id;
        $class = 'Drift'.$id.'Resource';
        $file = $dir.'/'.$class.'.php';
        File::put($file, "<?php\nnamespace {$namespace};\n\n".str_replace('{CLASS}', $class, $body)."\n");
        require (string) realpath($file);

        return $namespace.'\\'.$class;
    }
}
