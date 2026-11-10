<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Resolvers\PhpDocShapeResolver;
use Pentacore\Typefinder\Support\GeneratedClasses;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use Tests\Fixtures\Resources\PhpDoc\AliasedShapeResource;
use Tests\Fixtures\Resources\PhpDoc\BrokenDocResource;
use Tests\Fixtures\Resources\PhpDoc\BrokenPhpstanReturnWithStubResource;
use Tests\Fixtures\Resources\PhpDoc\Concerns\BaseShapeResource;
use Tests\Fixtures\Resources\PhpDoc\ImportCycleResource;
use Tests\Fixtures\Resources\PhpDoc\ImportedAliasResource;
use Tests\Fixtures\Resources\PhpDoc\InheritDocShapeResource;
use Tests\Fixtures\Resources\PhpDoc\InheritedShapeResource;
use Tests\Fixtures\Resources\PhpDoc\LaravelUnionShapeResource;
use Tests\Fixtures\Resources\PhpDoc\ListTopLevelResource;
use Tests\Fixtures\Resources\PhpDoc\ManyImportsResource;
use Tests\Fixtures\Resources\PhpDoc\MissingImportResource;
use Tests\Fixtures\Resources\PhpDoc\MutualImportResource;
use Tests\Fixtures\Resources\PhpDoc\NestedTraitToArrayResource;
use Tests\Fixtures\Resources\PhpDoc\NoOverrideResource;
use Tests\Fixtures\Resources\PhpDoc\PlainArrayResource;
use Tests\Fixtures\Resources\PhpDoc\PrecedenceResource;
use Tests\Fixtures\Resources\PhpDoc\PsalmReturnResource;
use Tests\Fixtures\Resources\PhpDoc\RecursiveImportResource;
use Tests\Fixtures\Resources\PhpDoc\StubDefaultResource;
use Tests\Fixtures\Resources\PhpDoc\TraitAliasToArrayResource;
use Tests\Fixtures\Resources\PhpDoc\TraitImportResource;
use Tests\Fixtures\Resources\PhpDoc\TraitToArrayResource;
use Tests\Fixtures\Resources\PhpDoc\TwoShapesResource;
use Tests\Fixtures\Resources\PhpDoc\UndocumentedTraitToArrayResource;
use Tests\TestCase;

final class PhpDocShapeResolverTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    /** @var list<string> */
    private array $tempDirectories = [];

    private PhpDocShapeResolver $phpDocShapeResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warnings = [];
        $this->phpDocShapeResolver = new PhpDocShapeResolver(
            new GeneratedClasses(
                models: [User::class],
                enums: [PostStatus::class],
                resources: [UserResource::class, PostResource::class, InheritedShapeResource::class, InheritDocShapeResource::class],
            ),
            function (string $message): void {
                $this->warnings[] = $message;
            },
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_phpstan_return_beats_psalm_return_and_return(): void
    {
        $this->assertSame(
            $this->object([$this->field('id', $this->ts('number')), $this->field('name', $this->ts('string'))]),
            $this->resolve(PrecedenceResource::class),
        );
    }

    public function test_psalm_return_beats_return(): void
    {
        $this->assertSame($this->object([$this->field('id', $this->ts('number'))]), $this->resolve(PsalmReturnResource::class));
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function fallThroughResources(): array
    {
        return [
            'stub default' => [StubDefaultResource::class],
            'plain Laravel signature' => [PlainArrayResource::class],
            'two shapes' => [TwoShapesResource::class],
            'top-level list shape' => [ListTopLevelResource::class],
            'no override' => [NoOverrideResource::class],
            'abstract' => [BaseShapeResource::class],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('fallThroughResources')]
    public function test_non_shapes_fall_through_silently(string $class): void
    {
        $this->assertNull($this->resolve($class));
        $this->assertSame([], $this->warnings);
    }

    public function test_shape_in_laravel_union_signature_is_used(): void
    {
        $this->assertSame($this->object([$this->field('id', $this->ts('number'))]), $this->resolve(LaravelUnionShapeResource::class));
    }

    public function test_unparsable_shape_warns_once_and_falls_through(): void
    {
        $this->assertNull($this->resolve(BrokenDocResource::class));
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString(
            'Could not parse @return on '.BrokenDocResource::class.'::toArray()',
            $this->warnings[0],
        );
    }

    public function test_unparsable_higher_precedence_tag_warns_next_to_a_valid_one(): void
    {
        $this->assertNull($this->resolve(BrokenPhpstanReturnWithStubResource::class));
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString(
            'Could not parse @phpstan-return on '.BrokenPhpstanReturnWithStubResource::class.'::toArray()',
            $this->warnings[0],
        );
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function inheritingResources(): array
    {
        return [
            'no override' => [InheritedShapeResource::class],
            'inheritDoc override' => [InheritDocShapeResource::class],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('inheritingResources')]
    public function test_inherited_doc_resolves_names_in_parent_and_static_to_child(string $class): void
    {
        $this->assertSame(
            $this->object([
                $this->field('id', $this->ts('number')),
                $this->field('owner', ['kind' => 'model', 'fqcn' => User::class]),
                $this->field('self', ['kind' => 'resource', 'fqcn' => $class]),
            ]),
            $this->resolve($class),
        );
        $this->assertSame([], $this->warnings);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function traitResources(): array
    {
        return [
            'trait on the resource' => [TraitToArrayResource::class],
            'trait used by a trait' => [NestedTraitToArrayResource::class],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('traitResources')]
    public function test_trait_provided_to_array_doc_is_read(string $class): void
    {
        $this->assertSame($this->object([$this->field('trait_field', $this->ts('string'))]), $this->resolve($class));
        $this->assertSame([], $this->warnings);
    }

    public function test_trait_doc_resolves_names_and_aliases_in_the_trait_file(): void
    {
        $this->assertSame(
            $this->object([
                $this->field('owner', ['kind' => 'model', 'fqcn' => User::class]),
                $this->field('meta', $this->object([$this->field('count', $this->ts('number'))])),
            ]),
            $this->resolve(TraitAliasToArrayResource::class),
        );
        $this->assertSame([], $this->warnings);
    }

    public function test_undocumented_trait_to_array_continues_to_the_parent(): void
    {
        $this->assertSame(
            $this->object([$this->field('base_only', $this->ts('number'))]),
            $this->resolve(UndocumentedTraitToArrayResource::class),
        );
    }

    public function test_class_aliases_expand(): void
    {
        $this->assertSame(
            $this->object([
                $this->field('meta', $this->object([$this->field('total', $this->ts('number'))])),
                $this->field('count', $this->ts('number')),
            ]),
            $this->resolve(AliasedShapeResource::class),
        );
        $this->assertSame([], $this->warnings);
    }

    public function test_imported_aliases_resolve_names_in_their_own_file(): void
    {
        $this->assertSame(
            $this->object([
                $this->field('address', $this->object([
                    $this->field('city', $this->ts('string')),
                    $this->field('owner', ['kind' => 'model', 'fqcn' => User::class]),
                ])),
                $this->field('contact', $this->object([
                    $this->field('person', $this->object([
                        $this->field('name', $this->ts('string')),
                        $this->field('status', ['kind' => 'enum', 'fqcn' => PostStatus::class]),
                    ])),
                ])),
            ]),
            $this->resolve(ImportedAliasResource::class),
        );
        $this->assertSame([], $this->warnings);
    }

    public function test_aliases_may_be_imported_from_a_trait(): void
    {
        $this->assertSame(
            $this->object([
                $this->field('price', $this->object([
                    $this->field('amount', $this->ts('number')),
                    $this->field('currency', $this->ts('string')),
                ])),
            ]),
            $this->resolve(TraitImportResource::class),
        );
        $this->assertSame([], $this->warnings);
    }

    public function test_classes_may_import_aliases_from_each_other(): void
    {
        $this->assertSame(
            $this->object([
                $this->field('remote', $this->object([
                    $this->field('local', $this->object([$this->field('n', $this->ts('number'))])),
                ])),
            ]),
            $this->resolve(MutualImportResource::class),
        );
        $this->assertSame([], $this->warnings);
    }

    public function test_many_mutual_imports_resolve_one_alias_at_a_time(): void
    {
        $this->assertSame(
            $this->object([
                $this->field('x', $this->object([
                    $this->field('b1', $this->object([$this->field('a1', $this->ts('number'))])),
                ])),
            ]),
            $this->resolve(ManyImportsResource::class),
        );
        $this->assertSame([], $this->warnings);
    }

    public function test_recursive_types_across_imports_terminate_with_unknown(): void
    {
        $this->assertSame(
            $this->object([
                $this->field('root', $this->object([
                    $this->field('leaf', $this->object([$this->field('branch', $this->ts('unknown'))])),
                ])),
            ]),
            $this->resolve(RecursiveImportResource::class),
        );
        $this->assertSame([
            RecursiveImportResource::class.'::toArray() @return: "root.leaf.branch" has unsupported type Branch — emitted as unknown',
        ], $this->warnings);
    }

    public function test_import_cycle_is_unknown_with_one_warning(): void
    {
        $this->assertSame($this->object([$this->field('loop', $this->ts('unknown'))]), $this->resolve(ImportCycleResource::class));
        $this->assertSame([
            ImportCycleResource::class.'::toArray() @return: "loop" has unsupported type Loop — emitted as unknown',
        ], $this->warnings);
    }

    public function test_missing_import_class_or_alias_is_unknown_with_warning(): void
    {
        $this->assertSame(
            $this->object([$this->field('a', $this->ts('unknown')), $this->field('b', $this->ts('unknown'))]),
            $this->resolve(MissingImportResource::class),
        );
        $this->assertSame([
            MissingImportResource::class.'::toArray() @return: "a" has unsupported type Nope — emitted as unknown',
            MissingImportResource::class.'::toArray() @return: "b" has unsupported type Address — emitted as unknown',
        ], $this->warnings);
    }

    public function test_doc_change_on_disk_wins_over_loaded_class(): void
    {
        $directory = sys_get_temp_dir().'/typefinder-phpdoc-'.bin2hex(random_bytes(4));
        $class = 'FreshDocResource'.bin2hex(random_bytes(4));
        $path = $directory.'/'.$class.'.php';
        File::ensureDirectoryExists($directory);

        try {
            File::put($path, $this->freshResource($class, 'array{id: int}'));
            require $path;
            $fqcn = 'Tests\Fixtures\Resources\PhpDoc\Temp\\'.$class;

            $this->assertSame($this->object([$this->field('id', $this->ts('number'))]), $this->resolve($fqcn));

            File::put($path, $this->freshResource($class, 'array{id: int, name: string}'));

            $this->assertSame(
                $this->object([$this->field('id', $this->ts('number')), $this->field('name', $this->ts('string'))]),
                $this->resolve($fqcn),
            );
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_top_level_alias_expands_to_the_shape_through_alias_chains(): void
    {
        $class = $this->loadTemp(<<<'PHP'
            /**
             * @phpstan-type Meta array{id: int}
             * @phpstan-type Outer Meta
             */
            class %s extends JsonResource
            {
                /** @return Outer */
                public function toArray($request): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertSame($this->object([$this->field('id', $this->ts('number'))]), $this->resolve($class));
        $this->assertSame([], $this->warnings);
    }

    public function test_top_level_alias_loop_falls_through_silently(): void
    {
        $class = $this->loadTemp(<<<'PHP'
            /**
             * @phpstan-type A B
             * @phpstan-type B A
             */
            class %s extends JsonResource
            {
                /** @return A */
                public function toArray($request): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertNull($this->resolve($class));
        $this->assertSame([], $this->warnings);
    }

    public function test_shape_in_union_with_a_non_laravel_member_falls_through_silently(): void
    {
        $class = $this->loadTemp(<<<'PHP'
            class %s extends JsonResource
            {
                /** @return array{id: int}|\stdClass */
                public function toArray($request): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertNull($this->resolve($class));
        $this->assertSame([], $this->warnings);
    }

    public function test_undocumented_to_array_directly_under_json_resource_falls_through(): void
    {
        $class = $this->loadTemp(<<<'PHP'
            class %s extends JsonResource
            {
                public function toArray($request): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertNull($this->resolve($class));
        $this->assertSame([], $this->warnings);
    }

    public function test_resource_whose_file_can_no_longer_be_parsed_falls_through_silently(): void
    {
        $class = $this->loadTemp(<<<'PHP'
            class %s extends JsonResource
            {
                /** @return array{id: int} */
                public function toArray($request): array
                {
                    return [];
                }
            }
            PHP);
        file_put_contents(new ReflectionClass($class)->getFileName(), '<?php class {');

        $this->assertNull($this->resolve($class));
        $this->assertSame([], $this->warnings);
    }

    public function test_import_from_a_class_whose_file_can_no_longer_be_parsed_is_unknown_with_warning(): void
    {
        $source = $this->loadTemp(<<<'PHP'
            /** @phpstan-type Foo array{a: int} */
            class %s {}
            PHP);
        file_put_contents(new ReflectionClass($source)->getFileName(), '<?php class {');
        $short = substr($source, (int) strrpos($source, '\\') + 1);

        $class = $this->loadTemp(<<<PHP
            /** @phpstan-import-type Foo from {$short} */
            class %s extends JsonResource
            {
                /** @return array{x: Foo} */
                public function toArray(\$request): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertSame($this->object([$this->field('x', $this->ts('unknown'))]), $this->resolve($class));
        $this->assertSame([$class.'::toArray() @return: "x" has unsupported type Foo — emitted as unknown'], $this->warnings);
    }

    public function test_import_from_a_class_that_fails_to_autoload_is_unknown_with_warning(): void
    {
        $missing = 'Tests\Fixtures\Resources\PhpDoc\Temp\ThrowsOnLoad'.bin2hex(random_bytes(4));
        $loader = static function (string $name) use ($missing): void {
            if ($name === $missing) {
                throw new RuntimeException('cannot load');
            }
        };
        spl_autoload_register($loader);

        try {
            $class = $this->loadTemp(<<<PHP
                /** @phpstan-import-type Foo from \\{$missing} */
                class %s extends JsonResource
                {
                    /** @return array{x: Foo} */
                    public function toArray(\$request): array
                    {
                        return [];
                    }
                }
                PHP);

            $this->assertSame($this->object([$this->field('x', $this->ts('unknown'))]), $this->resolve($class));
        } finally {
            spl_autoload_unregister($loader);
        }

        $this->assertSame([$class.'::toArray() @return: "x" has unsupported type Foo — emitted as unknown'], $this->warnings);
    }

    /**
     * Write a resource class (`%s` is replaced by a unique name) to a temp file and load it.
     *
     * @return class-string
     */
    private function loadTemp(string $classCode): string
    {
        $directory = sys_get_temp_dir().'/typefinder-phpdoc-'.bin2hex(random_bytes(4));
        $short = 'TempResource'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($directory);
        $this->tempDirectories[] = $directory;

        $path = $directory.'/'.$short.'.php';
        File::put($path, "<?php\n\nnamespace Tests\\Fixtures\\Resources\\PhpDoc\\Temp;\n\nuse Illuminate\\Http\\Resources\\Json\\JsonResource;\n\n".sprintf($classCode, $short));
        require $path;

        /** @var class-string $fqcn */
        $fqcn = 'Tests\\Fixtures\\Resources\\PhpDoc\\Temp\\'.$short;

        return $fqcn;
    }

    /**
     * @param  class-string|string  $class
     * @return ?array<string, mixed>
     */
    private function resolve(string $class): ?array
    {
        return $this->phpDocShapeResolver->resolve(new ReflectionClass($class));
    }

    private function freshResource(string $class, string $shape): string
    {
        return <<<PHP
            <?php

            namespace Tests\\Fixtures\\Resources\\PhpDoc\\Temp;

            use Illuminate\\Http\\Resources\\Json\\JsonResource;

            class {$class} extends JsonResource
            {
                /**
                 * @return {$shape}
                 */
                public function toArray(\$request): array
                {
                    return [];
                }
            }
            PHP;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function object(array $fields): array
    {
        return ['kind' => 'object', 'fields' => $fields, 'open' => false];
    }

    /**
     * @param  array<string, mixed>  $type
     * @return array<string, mixed>
     */
    private function field(string $name, array $type): array
    {
        return ['name' => $name, 'type' => $type, 'optional' => false];
    }

    /** @return array{kind: 'ts', ts: string} */
    private function ts(string $ts): array
    {
        return ['kind' => 'ts', 'ts' => $ts];
    }
}
