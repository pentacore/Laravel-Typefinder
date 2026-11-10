<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Http\Resources\ComposedLiteralResource;
use App\Http\Resources\ComposedParentResource;
use App\Http\Resources\ComposedResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\TagResource;
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
use Tests\Fixtures\Resources\PhpDoc\Composition\ChainAtLimitResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\ChainTooDeepResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\DocumentedDelegatingResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\HelperCycleResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\NestedHelperResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\ParentHelperResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\MergeBaseResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\PrivateCollisionResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\PrivateTraitResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\SelfRecursiveHelperResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\SkipLevelParentResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\TraitHelperResource;
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

    public function test_inherit_doc_override_whose_body_delegates_to_parent_is_composed(): void
    {
        // Breaking change in phase 3: the body `[...parent::toArray($r), 'extra' => true]`
        // composes the parent's documented shape with an undocumented `extra`.
        $this->assertSame(
            $this->object([
                $this->field('id', $this->ts('number')),
                $this->field('owner', ['kind' => 'model', 'fqcn' => User::class]),
                $this->field('self', ['kind' => 'resource', 'fqcn' => InheritDocShapeResource::class]),
                $this->field('extra', $this->ts('unknown')),
            ]),
            $this->resolve(InheritDocShapeResource::class),
        );
        $this->assertSame([$this->composedWarning(InheritDocShapeResource::class, ['extra'])], $this->warnings);
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

    public function test_composes_documented_helpers(): void
    {
        $this->assertSame(
            $this->object([
                $this->field('id', $this->ts('number')),
                $this->field('title', $this->ts('string')),
                $this->field('status', ['kind' => 'enum', 'fqcn' => PostStatus::class]),
                $this->field('comments_count', $this->ts('number')),
                $this->field('author', ['kind' => 'resource', 'fqcn' => UserResource::class], true),
            ]),
            $this->resolve(ComposedResource::class),
        );
        $this->assertSame([], $this->warnings);
    }

    public function test_composes_a_documented_parent_to_array_with_a_helper(): void
    {
        $this->assertSame(
            $this->object([
                $this->field('id', $this->ts('number')),
                $this->field('owner', ['kind' => 'model', 'fqcn' => User::class]),
                $this->field('self', ['kind' => 'union', 'of' => [['kind' => 'resource', 'fqcn' => ComposedParentResource::class], $this->ts('null')]]),
                $this->field('tags', ['kind' => 'array', 'of' => $this->ts('string')]),
                $this->field('featured', $this->ts('boolean'), true),
            ]),
            $this->resolve(ComposedParentResource::class),
        );
        $this->assertSame([], $this->warnings);
    }

    public function test_literal_keys_beside_helpers_are_unknown_with_one_warning(): void
    {
        $this->assertSame(
            $this->object([
                $this->field('id', $this->ts('number')),
                $this->field('title', $this->ts('string')),
                $this->field('note', $this->ts('unknown')),
            ]),
            $this->resolve(ComposedLiteralResource::class),
        );
        $this->assertSame([
            ComposedLiteralResource::class.'::toArray() composes a shape but "note" has no documented type — add @return array{…} to toArray()',
        ], $this->warnings);
    }

    public function test_parent_to_array_from_json_resource_is_not_composable(): void
    {
        $this->assertNull($this->resolve(TagResource::class));
        $this->assertSame([], $this->warnings);
    }

    public function test_undocumented_helper_that_delegates_is_composed(): void
    {
        $this->assertSame(
            $this->object([
                $this->field('id', $this->ts('number')),
                $this->field('label', $this->ts('unknown')),
            ]),
            $this->resolve(NestedHelperResource::class),
        );
        $this->assertSame([$this->composedWarning(NestedHelperResource::class, ['label'])], $this->warnings);
    }

    public function test_three_delegations_deep_is_composed(): void
    {
        $this->assertSame($this->object([$this->field('deep', $this->ts('number'))]), $this->resolve(ChainAtLimitResource::class));
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function notComposableResources(): array
    {
        return [
            'self-recursive helper' => [SelfRecursiveHelperResource::class],
            'two-helper cycle' => [HelperCycleResource::class],
            'four delegations deep' => [ChainTooDeepResource::class],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('notComposableResources')]
    public function test_recursion_and_depth_are_not_composable(string $class): void
    {
        $this->assertNull($this->resolve($class));
        $this->assertSame([], $this->warnings);
    }

    public function test_documented_to_array_wins_over_composition(): void
    {
        $this->assertSame($this->object([$this->field('declared', $this->ts('string'))]), $this->resolve(DocumentedDelegatingResource::class));
    }

    public function test_trait_helper_and_self_static_calls_resolve_in_their_declaring_file(): void
    {
        $this->assertSame(
            $this->object([
                $this->field('owner', ['kind' => 'model', 'fqcn' => User::class]),
                $this->field('id', $this->ts('number')),
                $this->field('self', ['kind' => 'resource', 'fqcn' => TraitHelperResource::class]),
            ]),
            $this->resolve(TraitHelperResource::class),
        );
        $this->assertSame([], $this->warnings);
    }

    public function test_abstract_parent_composes_and_parent_helpers_resolve_in_the_parent_file(): void
    {
        $this->assertSame(
            $this->object([
                $this->field('status', ['kind' => 'enum', 'fqcn' => PostStatus::class]),
                $this->field('author', ['kind' => 'model', 'fqcn' => User::class]),
            ]),
            $this->resolve(ParentHelperResource::class),
        );
        $this->assertSame([], $this->warnings);
    }

    public function test_private_helper_is_resolved_in_the_class_whose_code_calls_it(): void
    {
        $this->assertSame(
            $this->object([
                $this->field('id', $this->ts('number')),
                $this->field('name', $this->ts('string')),
            ]),
            $this->resolve(PrivateCollisionResource::class),
        );
        $this->assertSame([], $this->warnings);
    }

    public function test_private_trait_helper_called_from_trait_code_is_resolved(): void
    {
        $this->assertSame($this->object([$this->field('hidden', $this->ts('boolean'))]), $this->resolve(PrivateTraitResource::class));
    }

    public function test_parent_to_array_reaches_the_ancestor_that_declares_it(): void
    {
        $this->assertSame(
            $this->object([
                $this->field('grand', $this->ts('number')),
                $this->field('own', $this->ts('string')),
            ]),
            $this->resolve(SkipLevelParentResource::class),
        );
        $this->assertSame([], $this->warnings);
    }

    public function test_unsupported_type_in_a_helper_doc_names_the_helper(): void
    {
        $class = $this->loadTemp(<<<'PHP'
            class %s extends JsonResource
            {
                public function toArray($request): array
                {
                    return [...$this->base()];
                }

                /** @return array{handler: callable} */
                private function base(): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertSame($this->object([$this->field('handler', $this->ts('unknown'))]), $this->resolve($class));
        $this->assertSame([$class.'::base() @return: "handler" has unsupported type callable — emitted as unknown'], $this->warnings);
    }

    public function test_unsupported_type_in_a_parent_to_array_doc_keeps_the_to_array_wording(): void
    {
        $parent = $this->loadTemp(<<<'PHP'
            abstract class %s extends JsonResource
            {
                /** @return array{handler: callable} */
                public function toArray($request): array
                {
                    return [];
                }
            }
            PHP);
        $short = substr($parent, (int) strrpos($parent, '\\') + 1);
        $class = $this->loadTemp(<<<PHP
            class %s extends {$short}
            {
                public function toArray(\$request): array
                {
                    return [...parent::toArray(\$request), ...\$this->base()];
                }

                /** @return array{id: int} */
                private function base(): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertNotNull($this->resolve($class));
        $this->assertSame([$class.'::toArray() @return: "handler" has unsupported type callable — emitted as unknown'], $this->warnings);
    }

    public function test_several_undocumented_keys_are_listed_in_one_warning_in_first_seen_order(): void
    {
        $class = $this->loadTemp(<<<'PHP'
            class %s extends JsonResource
            {
                public function toArray($request): array
                {
                    return ['b' => 1, ...$this->base(), 'a' => 2];
                }

                /** @return array{id: int} */
                private function base(): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertSame(
            $this->object([
                $this->field('b', $this->ts('unknown')),
                $this->field('id', $this->ts('number')),
                $this->field('a', $this->ts('unknown')),
            ]),
            $this->resolve($class),
        );
        $this->assertSame([
            $class.'::toArray() composes a shape but "b", "a" have no documented type — add @return array{…} to toArray()',
        ], $this->warnings);
    }

    public function test_a_documented_key_overriding_a_nested_literal_is_not_reported(): void
    {
        $class = $this->loadTemp(<<<'PHP'
            class %s extends JsonResource
            {
                public function toArray($request): array
                {
                    return [...$this->loose(), ...$this->strict()];
                }

                private function loose(): array
                {
                    return [...$this->strict(), 'id' => 1, 'extra' => 2];
                }

                /** @return array{id: int} */
                private function strict(): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertSame(
            $this->object([
                $this->field('id', $this->ts('number')),
                $this->field('extra', $this->ts('unknown')),
            ]),
            $this->resolve($class),
        );
        $this->assertSame([$this->composedWarning($class, ['extra'])], $this->warnings);
    }

    public function test_stub_default_doc_on_to_array_does_not_block_composition(): void
    {
        $class = $this->loadTemp(<<<'PHP'
            class %s extends JsonResource
            {
                /** @return array<string, mixed> */
                public function toArray($request): array
                {
                    return $this->base();
                }

                /** @phpstan-return array{id: int} */
                private function base(): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertSame($this->object([$this->field('id', $this->ts('number'))]), $this->resolve($class));
        $this->assertSame([], $this->warnings);
    }

    public function test_conditional_helper_and_several_returns_make_keys_optional(): void
    {
        $class = $this->loadTemp(<<<'PHP'
            class %s extends JsonResource
            {
                public function toArray($request): array
                {
                    if ($this->resource === null) {
                        return [];
                    }

                    return [...$this->base(), $this->mergeWhen($this->admin, $this->admin())];
                }

                /** @return array{id: int} */
                private function base(): array
                {
                    return [];
                }

                /** @return array{secret: string} */
                private function admin(): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertSame(
            $this->object([
                $this->field('id', $this->ts('number'), true),
                $this->field('secret', $this->ts('string'), true),
            ]),
            $this->resolve($class),
        );
    }

    public function test_open_helper_shape_makes_the_composed_shape_open(): void
    {
        $class = $this->loadTemp(<<<'PHP'
            class %s extends JsonResource
            {
                public function toArray($request): array
                {
                    return $this->base();
                }

                /** @return array{id: int, ...} */
                private function base(): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertSame(
            ['kind' => 'object', 'fields' => [$this->field('id', $this->ts('number'))], 'open' => true],
            $this->resolve($class),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notComposableBodies(): array
    {
        return [
            'literal keys only' => ["return ['id' => \$this->id];"],
            'empty return only' => ['return [];'],
            'parent from JsonResource' => ['return [...parent::toArray($request), ...$this->base()];'],
            'variable' => ['$data = $this->base(); return $data;'],
            'foreign call' => ['return [...$this->base(), ...$this->other->part()];'],
            'helper of another class' => ['return [...$this->base(), ...Other::part()];'],
            'parent helper other than toArray' => ['return [...$this->base(), ...parent::jsonOptions()];'],
            'first-class callable' => ['return $this->base(...);'],
            'missing method' => ['return [...$this->base(), ...$this->missing()];'],
            'undocumented literal-only helper' => ['return [...$this->base(), ...$this->literal()];'],
            'non-shape helper doc with an opaque body' => ['return [...$this->base(), ...$this->loose()];'],
            'dynamic key' => ['return [...$this->base(), $this->key => 1];'],
            'plus with a repeated key' => ["return \$this->base() + ['id' => 1];"],
            'unsupported helper type is not reported when composition fails' => ['return [...$this->unmappable(), ...$this->missing()];'],
        ];
    }

    #[DataProvider('notComposableBodies')]
    public function test_bodies_that_are_not_composable_fall_through_silently(string $body): void
    {
        $class = $this->loadTemp(<<<PHP
            class %s extends JsonResource
            {
                public function toArray(\$request): array
                {
                    {$body}
                }

                /** @return array{id: int} */
                private function base(): array
                {
                    return [];
                }

                private function literal(): array
                {
                    return ['x' => 1];
                }

                /** @return array */
                private function loose(): array
                {
                    return \$this->resource->toArray();
                }

                /** @return array{handler: callable} */
                private function unmappable(): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertNull($this->resolve($class));
        $this->assertSame([], $this->warnings);
    }

    public function test_helper_with_a_non_shape_doc_composes_its_body(): void
    {
        $class = $this->loadTemp(<<<'PHP'
            class %s extends JsonResource
            {
                public function toArray($request): array
                {
                    return $this->loose();
                }

                /** @return array<string, mixed> */
                private function loose(): array
                {
                    return [...$this->base()];
                }

                /** @return array{id: int} */
                private function base(): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertSame($this->object([$this->field('id', $this->ts('number'))]), $this->resolve($class));
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

    public function test_helper_added_on_disk_after_the_class_loaded_is_composed(): void
    {
        $class = $this->loadTemp(<<<'PHP'
            class %s extends JsonResource
            {
                public function toArray($request): array
                {
                    return [...$this->base()];
                }

                /** @return array{id: int} */
                private function base(): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertSame($this->object([$this->field('id', $this->ts('number'))]), $this->resolve($class));

        // typefinder:watch: the class stays loaded with the old method set.
        $this->rewrite($class, [
            'return [...$this->base()];' => 'return [...$this->base(), ...$this->counts()];',
            '/** @return array{id: int} */' => "/** @return array{posts_count: int} */\n    private function counts(): array\n    {\n        return [];\n    }\n\n    /** @return array{id: int} */",
        ]);
        $this->assertFalse(method_exists($class, 'counts'));

        $this->assertSame(
            $this->object([$this->field('id', $this->ts('number')), $this->field('posts_count', $this->ts('number'))]),
            $this->resolve($class),
        );
        $this->assertSame([], $this->warnings);
    }

    public function test_private_helper_added_on_disk_to_a_parent_dispatches_to_the_parent(): void
    {
        $parent = $this->loadTemp(<<<'PHP'
            abstract class %s extends JsonResource
            {
                public function toArray($request): array
                {
                    return [...$this->base()];
                }

                /** @return array{id: int} */
                private function base(): array
                {
                    return [];
                }
            }
            PHP);
        $short = substr($parent, (int) strrpos($parent, '\\') + 1);
        $class = $this->loadTemp(<<<PHP
            class %s extends {$short}
            {
                public function toArray(\$request): array
                {
                    return [...parent::toArray(\$request), ...\$this->own()];
                }

                /** @return array{own: bool} */
                private function own(): array
                {
                    return [];
                }

                /** @return array{wrong: string} */
                public function extra(): array
                {
                    return [];
                }
            }
            PHP);

        $this->rewrite($parent, [
            'return [...$this->base()];' => 'return [...$this->base(), ...$this->extra()];',
            '/** @return array{id: int} */' => "/** @return array{right: int} */\n    private function extra(): array\n    {\n        return [];\n    }\n\n    /** @return array{id: int} */",
        ]);

        $this->assertSame(
            $this->object([
                $this->field('id', $this->ts('number')),
                $this->field('right', $this->ts('number')),
                $this->field('own', $this->ts('boolean')),
            ]),
            $this->resolve($class),
        );
    }

    public function test_to_array_added_on_disk_to_a_parent_is_what_parent_to_array_reaches(): void
    {
        $grand = $this->loadTemp(<<<'PHP'
            abstract class %s extends JsonResource
            {
                /** @return array{grand: int} */
                public function toArray($request): array
                {
                    return [];
                }
            }
            PHP);
        $grandShort = substr($grand, (int) strrpos($grand, '\\') + 1);
        $parent = $this->loadTemp(<<<PHP
            abstract class %s extends {$grandShort}
            {
            }
            PHP);
        $parentShort = substr($parent, (int) strrpos($parent, '\\') + 1);
        $class = $this->loadTemp(<<<PHP
            class %s extends {$parentShort}
            {
                public function toArray(\$request): array
                {
                    return [...parent::toArray(\$request), ...\$this->own()];
                }

                /** @return array{own: bool} */
                private function own(): array
                {
                    return [];
                }
            }
            PHP);

        $this->rewrite($parent, [
            "{\n}" => "{\n    /** @return array{mid: string} */\n    public function toArray(\$request): array\n    {\n        return [];\n    }\n}",
        ]);

        $this->assertSame(
            $this->object([$this->field('mid', $this->ts('string')), $this->field('own', $this->ts('boolean'))]),
            $this->resolve($class),
        );
    }

    public function test_helper_removed_from_disk_is_not_composable(): void
    {
        $class = $this->loadTemp(<<<'PHP'
            class %s extends JsonResource
            {
                public function toArray($request): array
                {
                    return [...$this->base(), ...$this->counts()];
                }

                /** @return array{id: int} */
                private function base(): array
                {
                    return [];
                }

                /** @return array{posts_count: int} */
                private function counts(): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertNotNull($this->resolve($class));

        $this->rewrite($class, [
            "/** @return array{posts_count: int} */\n    private function counts(): array\n    {\n        return [];\n    }\n" => '',
        ]);
        $this->assertTrue(method_exists($class, 'counts'));

        $this->assertNull($this->resolve($class));
        $this->assertSame([], $this->warnings);
    }

    public function test_a_helper_warning_is_emitted_once_however_often_the_helper_is_used(): void
    {
        $class = $this->loadTemp(<<<'PHP'
            class %s extends JsonResource
            {
                public function toArray($request): array
                {
                    if ($request->boolean('x')) {
                        return [...$this->base(), 'a' => 1];
                    }

                    return [...$this->base(), ...$this->nested()];
                }

                private function nested(): array
                {
                    return [...$this->base()];
                }

                /** @return array{handler: callable} */
                private function base(): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertSame(
            $this->object([
                $this->field('handler', $this->ts('unknown')),
                $this->field('a', $this->ts('unknown'), true),
            ]),
            $this->resolve($class),
        );
        $this->assertSame([
            $class.'::base() @return: "handler" has unsupported type callable — emitted as unknown',
            $this->composedWarning($class, ['a']),
        ], $this->warnings);
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

    public function test_resolve_documented_skips_abstract_resources_and_other_classes(): void
    {
        $this->assertNull($this->phpDocShapeResolver->resolveDocumented(
            new ReflectionClass(MergeBaseResource::class),
        ));
        $this->assertNull($this->phpDocShapeResolver->resolveDocumented(new ReflectionClass(\stdClass::class)));
    }

    /**
     * @param  class-string|string  $class
     * @return ?array<string, mixed>
     */
    private function resolve(string $class): ?array
    {
        return $this->phpDocShapeResolver->resolve(new ReflectionClass($class));
    }

    /**
     * Edit a loaded class's file on disk (each search string must occur once);
     * the loaded class keeps its old methods.
     *
     * @param  array<string, string>  $replacements
     */
    private function rewrite(string $class, array $replacements): void
    {
        $path = (string) new ReflectionClass($class)->getFileName();
        $code = File::get($path);

        foreach ($replacements as $search => $replace) {
            $this->assertSame(1, substr_count($code, $search), 'not found once: '.$search);
            $code = str_replace($search, $replace, $code);
        }

        File::put($path, $code);
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
    private function field(string $name, array $type, bool $optional = false): array
    {
        return ['name' => $name, 'type' => $type, 'optional' => $optional];
    }

    /**
     * @param  list<string>  $keys
     */
    private function composedWarning(string $class, array $keys): string
    {
        $quoted = implode(', ', array_map(static fn (string $key): string => '"'.$key.'"', $keys));

        return sprintf(
            '%s::toArray() composes a shape but %s %s no documented type — add @return array{…} to toArray()',
            $class,
            $quoted,
            count($keys) === 1 ? 'has' : 'have',
        );
    }

    /** @return array{kind: 'ts', ts: string} */
    private function ts(string $ts): array
    {
        return ['kind' => 'ts', 'ts' => $ts];
    }
}
