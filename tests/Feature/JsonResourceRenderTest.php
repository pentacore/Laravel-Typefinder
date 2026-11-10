<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Resolvers\PhpDocShapeResolver;
use Pentacore\Typefinder\Support\GeneratedClasses;
use ReflectionClass;
use Tests\TestCase;

final class JsonResourceRenderTest extends TestCase
{
    public function test_renders_tier_1_explicit_shape(): void
    {
        $typeScriptRenderer = new TypeScriptRenderer;

        $resource = [
            'name' => 'PostResource',
            'fqcn' => PostResource::class,
            'shape' => [
                'kind' => 'shape',
                'fields' => [
                    'id' => 'number',
                    'title' => 'string',
                    'author' => UserResource::class,
                    'published_at' => 'string | null',
                ],
            ],
        ];

        $output = $typeScriptRenderer->renderResource(
            $resource,
            [],
            [],
            [['name' => 'UserResource', 'fqcn' => UserResource::class]],
        );

        $this->assertStringContainsString("import type { UserResource } from './UserResource';", $output);
        $this->assertStringContainsString('export type PostResource = {', $output);
        $this->assertStringContainsString('  id: number;', $output);
        $this->assertStringContainsString('  title: string;', $output);
        $this->assertStringContainsString('  author: UserResource;', $output);
        $this->assertStringContainsString('  published_at: string | null;', $output);
    }

    public function test_renders_tier_2_model_extension_with_omit_and_extend(): void
    {
        $typeScriptRenderer = new TypeScriptRenderer;

        $resource = [
            'name' => 'AdminUserResource',
            'fqcn' => AdminUserResource::class,
            'shape' => [
                'kind' => 'model',
                'model' => User::class,
                'omit' => ['password'],
                'extend' => ['roles' => 'string[]'],
            ],
        ];

        $output = $typeScriptRenderer->renderResource(
            $resource,
            [['name' => 'User', 'fqcn' => User::class]],
            [],
            [],
        );

        $this->assertStringContainsString("import type { User } from '../models';", $output);
        $this->assertStringContainsString("export type AdminUserResource = Omit<User, 'password'> & { roles: string[] };", $output);
    }

    public function test_renders_tier_2_with_empty_omit_skips_omit_wrapper(): void
    {
        $typeScriptRenderer = new TypeScriptRenderer;

        $resource = [
            'name' => 'UserResource',
            'fqcn' => UserResource::class,
            'shape' => [
                'kind' => 'model',
                'model' => User::class,
                'omit' => [],
                'extend' => [],
            ],
        ];

        $output = $typeScriptRenderer->renderResource(
            $resource,
            [['name' => 'User', 'fqcn' => User::class]],
            [],
            [],
        );

        $this->assertStringNotContainsString('Omit<', $output);
        $this->assertStringNotContainsString(' & {', $output);
        $this->assertStringContainsString('export type UserResource = User;', $output);
    }

    public function test_renders_tier_2_with_extend_only(): void
    {
        $typeScriptRenderer = new TypeScriptRenderer;

        $resource = [
            'name' => 'AdminUserResource',
            'fqcn' => AdminUserResource::class,
            'shape' => [
                'kind' => 'model',
                'model' => User::class,
                'omit' => [],
                'extend' => ['roles' => 'string[]'],
            ],
        ];

        $output = $typeScriptRenderer->renderResource(
            $resource,
            [['name' => 'User', 'fqcn' => User::class]],
            [],
            [],
        );

        $this->assertStringNotContainsString('Omit<', $output);
        $this->assertStringContainsString('export type AdminUserResource = User & { roles: string[] };', $output);
    }

    private function renderPhpdoc(array $type, array $models = [], array $enums = [], array $resources = [], string $fqcn = PostResource::class): string
    {
        return (new TypeScriptRenderer)->renderResource(
            ['name' => 'PostResource', 'fqcn' => $fqcn, 'shape' => ['kind' => 'phpdoc', 'type' => $type]],
            $models,
            $enums,
            $resources,
        );
    }

    private function ts(string $ts): array
    {
        return ['kind' => 'ts', 'ts' => $ts];
    }

    private function field(string $name, array $type, bool $optional = false): array
    {
        return ['name' => $name, 'type' => $type, 'optional' => $optional];
    }

    public function test_renders_phpdoc_shape_like_attribute_shape(): void
    {
        $resources = [['name' => 'UserResource', 'fqcn' => UserResource::class]];

        $phpdoc = $this->renderPhpdoc([
            'kind' => 'object',
            'open' => false,
            'fields' => [
                $this->field('id', $this->ts('number')),
                $this->field('title', $this->ts('string')),
                $this->field('author', ['kind' => 'resource', 'fqcn' => UserResource::class]),
            ],
        ], [], [], $resources);

        $attribute = (new TypeScriptRenderer)->renderResource([
            'name' => 'PostResource',
            'fqcn' => PostResource::class,
            'shape' => ['kind' => 'shape', 'fields' => [
                'id' => 'number',
                'title' => 'string',
                'author' => UserResource::class,
            ]],
        ], [], [], $resources);

        $this->assertSame($attribute, $phpdoc);
    }

    public function test_phpdoc_nested_types(): void
    {
        $user = ['kind' => 'model', 'fqcn' => User::class];
        $output = $this->renderPhpdoc([
            'kind' => 'object',
            'open' => false,
            'fields' => [
                $this->field('meta', ['kind' => 'object', 'open' => false, 'fields' => [
                    $this->field('total', $this->ts('number')),
                    $this->field('tags', ['kind' => 'array', 'of' => $this->ts('string')]),
                ]]),
                $this->field('users', ['kind' => 'array', 'of' => ['kind' => 'union', 'of' => [$user, $this->ts('null')]]]),
                $this->field('counts', ['kind' => 'record', 'of' => $this->ts('number')]),
                $this->field('pair', ['kind' => 'tuple', 'items' => [
                    ['type' => $this->ts('number'), 'optional' => false],
                    ['type' => $this->ts('string'), 'optional' => true],
                ]]),
                $this->field('loose', ['kind' => 'object', 'open' => true, 'fields' => [$this->field('a', $this->ts('number'))]]),
                $this->field('posts', ['kind' => 'array', 'of' => ['kind' => 'resource', 'fqcn' => PostResource::class]], true),
                $this->field('dup', ['kind' => 'union', 'of' => [$this->ts('string'), $this->ts('null'), $this->ts('null')]]),
                $this->field('num', ['kind' => 'array', 'of' => $this->ts('number | string')]),
                $this->field('both', ['kind' => 'intersection', 'of' => [
                    ['kind' => 'union', 'of' => [$this->ts('A'), $this->ts('B')]],
                    $this->ts('C'),
                ]]),
            ],
        ], [['name' => 'User', 'fqcn' => User::class]]);

        $this->assertStringContainsString('  meta: { total: number; tags: string[] };', $output);
        $this->assertStringContainsString('  users: (User | null)[];', $output);
        $this->assertStringContainsString('  counts: Record<string, number>;', $output);
        $this->assertStringContainsString('  pair: [number, string?];', $output);
        $this->assertStringContainsString('  loose: { a: number; [key: string]: unknown };', $output);
        $this->assertStringContainsString('  posts?: PostResource[];', $output);
        $this->assertStringContainsString('  dup: string | null;', $output);
        $this->assertStringContainsString('  num: (number | string)[];', $output);
        $this->assertStringContainsString('  both: (A | B) & C;', $output);
    }

    public function test_phpdoc_imports(): void
    {
        $output = $this->renderPhpdoc([
            'kind' => 'object',
            'open' => false,
            'fields' => [
                $this->field('status', ['kind' => 'enum', 'fqcn' => PostStatus::class]),
                $this->field('user', ['kind' => 'model', 'fqcn' => User::class]),
                $this->field('again', ['kind' => 'model', 'fqcn' => User::class]),
                $this->field('author', ['kind' => 'resource', 'fqcn' => UserResource::class]),
                $this->field('self', ['kind' => 'resource', 'fqcn' => PostResource::class]),
            ],
        ]);

        $this->assertStringContainsString(
            "import type { PostStatus } from '../enums';\nimport type { User } from '../models';\nimport type { UserResource } from './UserResource';\n\n",
            $output,
        );
        $this->assertSame(1, substr_count($output, 'import type { User }'));
        $this->assertStringNotContainsString("from './PostResource'", $output);
        $this->assertStringContainsString('  self: PostResource;', $output);
    }

    public function test_optional_tuple_item_with_union_is_parenthesised(): void
    {
        $output = $this->renderPhpdoc([
            'kind' => 'object',
            'open' => false,
            'fields' => [
                $this->field('pair', ['kind' => 'tuple', 'items' => [
                    ['type' => $this->ts('number'), 'optional' => false],
                    ['type' => ['kind' => 'union', 'of' => [$this->ts('string'), $this->ts('null')]], 'optional' => true],
                ]]),
            ],
        ]);

        $this->assertStringContainsString('  pair: [number, (string | null)?];', $output);
    }

    public function test_object_keys_are_quoted_when_not_identifiers(): void
    {
        $output = $this->renderPhpdoc([
            'kind' => 'object',
            'open' => false,
            'fields' => [
                $this->field('foo-bar', $this->ts('string')),
                $this->field('0', $this->ts('string')),
                $this->field("it's", $this->ts('string')),
                $this->field('$valid_1', $this->ts('string')),
            ],
        ]);

        $this->assertStringContainsString("  'foo-bar': string;", $output);
        $this->assertStringContainsString("  '0': string;", $output);
        $this->assertStringContainsString("  'it\\'s': string;", $output);
        $this->assertStringContainsString('  $valid_1: string;', $output);
    }

    public function test_phpdoc_nested_class_leaves_keep_their_imports(): void
    {
        $output = $this->renderPhpdoc([
            'kind' => 'object',
            'open' => false,
            'fields' => [
                $this->field('users', ['kind' => 'array', 'of' => ['kind' => 'union', 'of' => [
                    ['kind' => 'model', 'fqcn' => User::class],
                    $this->ts('null'),
                ]]]),
                $this->field('authors', ['kind' => 'array', 'of' => ['kind' => 'resource', 'fqcn' => UserResource::class]]),
                $this->field('children', ['kind' => 'array', 'of' => ['kind' => 'resource', 'fqcn' => PostResource::class]]),
                $this->field('byStatus', ['kind' => 'record', 'of' => ['kind' => 'enum', 'fqcn' => PostStatus::class]]),
            ],
        ]);

        $this->assertStringContainsString("import type { User } from '../models';", $output);
        $this->assertStringContainsString("import type { UserResource } from './UserResource';", $output);
        $this->assertStringContainsString("import type { PostStatus } from '../enums';", $output);
        $this->assertStringNotContainsString("from './PostResource'", $output);
        $this->assertStringContainsString('  children: PostResource[];', $output);
    }

    public function test_phpdoc_namespace_less_classes_render_their_names_and_imports(): void
    {
        foreach (['TypefinderGlobalModel', 'TypefinderGlobalStatus', 'TypefinderGlobalItemResource', 'TypefinderGlobalResource'] as $fixture) {
            require_once __DIR__.'/../Fixtures/GlobalNamespace/'.$fixture.'.php';
        }

        $warnings = [];
        $shape = new PhpDocShapeResolver(
            new GeneratedClasses(
                models: ['TypefinderGlobalModel'],
                enums: ['TypefinderGlobalStatus'],
                resources: ['TypefinderGlobalItemResource', 'TypefinderGlobalResource'],
            ),
            static function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            },
        )->resolve(new ReflectionClass('TypefinderGlobalResource'));

        $this->assertNotNull($shape);
        $this->assertSame([], $warnings);

        $output = (new TypeScriptRenderer)->renderResource(
            ['name' => 'TypefinderGlobalResource', 'fqcn' => 'TypefinderGlobalResource', 'shape' => ['kind' => 'phpdoc', 'type' => $shape]],
            [['name' => 'TypefinderGlobalModel', 'fqcn' => 'TypefinderGlobalModel']],
            [['name' => 'TypefinderGlobalStatus', 'fqcn' => 'TypefinderGlobalStatus']],
            [
                ['name' => 'TypefinderGlobalItemResource', 'fqcn' => 'TypefinderGlobalItemResource'],
                ['name' => 'TypefinderGlobalResource', 'fqcn' => 'TypefinderGlobalResource'],
            ],
        );

        $this->assertStringContainsString(
            "import type { TypefinderGlobalItemResource } from './TypefinderGlobalItemResource';\n"
            ."import type { TypefinderGlobalModel } from '../models';\n"
            ."import type { TypefinderGlobalStatus } from '../enums';\n\n",
            $output,
        );
        $this->assertStringContainsString('  owner: TypefinderGlobalModel;', $output);
        $this->assertStringContainsString('  status: TypefinderGlobalStatus;', $output);
        $this->assertStringContainsString('  item: TypefinderGlobalItemResource;', $output);
        $this->assertStringContainsString('  self: TypefinderGlobalResource;', $output);
        $this->assertStringNotContainsString("from './TypefinderGlobalResource'", $output);
    }

    public function test_phpdoc_unresolvable_class_leaf_renders_unknown(): void
    {
        $output = $this->renderPhpdoc([
            'kind' => 'object',
            'open' => false,
            'fields' => [$this->field('x', ['kind' => 'model', 'fqcn' => 'Nope\\Missing'])],
        ]);

        $this->assertStringContainsString('  x: unknown;', $output);
    }

    public function test_phpdoc_top_level_non_object_renders_as_alias(): void
    {
        $output = $this->renderPhpdoc(['kind' => 'array', 'of' => $this->ts('string')]);

        $this->assertStringEndsWith("export type PostResource = string[];\n", $output);
    }

    public function test_phpdoc_empty_object_renders_braces(): void
    {
        $output = $this->renderPhpdoc(['kind' => 'object', 'open' => false, 'fields' => []]);

        $this->assertStringEndsWith("export type PostResource = {};\n", $output);
    }

    public function test_phpdoc_open_top_level_object_gets_index_signature(): void
    {
        $output = $this->renderPhpdoc([
            'kind' => 'object',
            'open' => true,
            'fields' => [$this->field('id', $this->ts('number'))],
        ]);

        $this->assertStringEndsWith("export type PostResource = {\n  id: number;\n  [key: string]: unknown;\n};\n", $output);
    }

    public function test_phpdoc_nested_unions_are_flattened_and_deduplicated(): void
    {
        $output = $this->renderPhpdoc([
            'kind' => 'object',
            'open' => false,
            'fields' => [
                $this->field('v', ['kind' => 'union', 'of' => [
                    $this->ts('string'),
                    ['kind' => 'union', 'of' => [$this->ts('null'), ['kind' => 'union', 'of' => [$this->ts('number'), $this->ts('string')]]]],
                ]]),
            ],
        ]);

        $this->assertStringContainsString('  v: string | null | number;', $output);
    }

    public function test_phpdoc_inline_nested_object_quotes_keys_and_marks_optional(): void
    {
        $output = $this->renderPhpdoc([
            'kind' => 'object',
            'open' => false,
            'fields' => [
                $this->field('meta', [
                    'kind' => 'object',
                    'open' => true,
                    'fields' => [
                        $this->field('a-b', $this->ts('string')),
                        $this->field('c', $this->ts('number'), true),
                    ],
                ]),
            ],
        ]);

        $this->assertStringContainsString("  meta: { 'a-b': string; c?: number; [key: string]: unknown };", $output);
    }
}
