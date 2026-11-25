<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\LegacyResource;
use App\Http\Resources\OrphanResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserResource;
use App\Models\LegacyModel;
use App\Models\Post;
use App\Models\User;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\TestCase;

/**
 * A model or resource that gets no generated type (ignored, outside the
 * configured paths, skipped) is `unknown`, with a warning, never an import of
 * a file that does not exist (#138).
 */
final class DanglingReferenceTest extends TestCase
{
    private const array MODELS = [['name' => 'User', 'fqcn' => User::class], ['name' => 'Post', 'fqcn' => Post::class]];

    private const array RESOURCES = [['name' => 'UserResource', 'fqcn' => UserResource::class]];

    private TypeScriptRenderer $typeScriptRenderer;

    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->warnings = [];
        $this->typeScriptRenderer = new TypeScriptRenderer;
        $this->typeScriptRenderer->onWarn(function (string $message): void {
            $this->warnings[] = $message;
        });
    }

    public function test_relation_to_a_model_with_no_type_is_unknown(): void
    {
        $model = [
            'name' => 'Post',
            'fqcn' => Post::class,
            'columns' => [['name' => 'id', 'type' => 'number', 'nullable' => false]],
            'relationships' => [
                ['name' => 'author', 'type' => 'belongsTo', 'related' => User::class],
                ['name' => 'legacy', 'type' => 'belongsTo', 'related' => LegacyModel::class],
                ['name' => 'legacies', 'type' => 'many', 'related' => LegacyModel::class],
            ],
        ];

        $output = $this->typeScriptRenderer->renderModel($model, [], self::MODELS);

        $this->assertStringContainsString("import type { User } from './User';", $output);
        $this->assertStringNotContainsString('LegacyModel', $output);
        $this->assertStringContainsString('  legacy?: unknown;', $output);
        $this->assertStringContainsString('  legacies?: unknown[];', $output);
        $this->assertCount(2, $this->warnings);
        $this->assertStringContainsString(LegacyModel::class.', which gets no generated type', $this->warnings[0]);
        $this->assertStringContainsString('relation "legacy"', $this->warnings[0]);
    }

    public function test_resource_shape_referencing_a_skipped_resource_is_unknown(): void
    {
        $resource = [
            'name' => 'PostResource',
            'fqcn' => PostResource::class,
            'shape' => ['kind' => 'shape', 'fields' => [
                'author' => UserResource::class,
                'orphan' => OrphanResource::class.'[]',
                'legacy' => LegacyModel::class.' | null',
            ]],
        ];

        $output = $this->typeScriptRenderer->renderResource($resource, self::MODELS, [], self::RESOURCES);

        $this->assertStringContainsString("import type { UserResource } from './UserResource';", $output);
        $this->assertStringNotContainsString('OrphanResource', $output);
        $this->assertStringNotContainsString('LegacyModel', $output);
        $this->assertStringContainsString('  orphan: unknown[];', $output);
        $this->assertStringContainsString('  legacy: unknown | null;', $output);
        $this->assertCount(2, $this->warnings);
    }

    public function test_model_typed_resource_of_a_model_with_no_type_is_unknown(): void
    {
        $resource = [
            'name' => 'LegacyResource',
            'fqcn' => LegacyResource::class,
            'shape' => ['kind' => 'model', 'model' => LegacyModel::class, 'omit' => ['id'], 'extend' => ['extra' => 'string']],
        ];

        $output = $this->typeScriptRenderer->renderResource($resource, self::MODELS, [], self::RESOURCES);

        $this->assertStringNotContainsString('import', $output);
        $this->assertStringContainsString('export type LegacyResource = Record<string, unknown> & { extra: string };', $output);
        $this->assertCount(1, $this->warnings);
    }

    public function test_phpdoc_leaf_of_a_skipped_resource_is_unknown(): void
    {
        $resource = [
            'name' => 'PostResource',
            'fqcn' => PostResource::class,
            'shape' => ['kind' => 'phpdoc', 'type' => ['kind' => 'object', 'open' => false, 'fields' => [
                ['name' => 'orphan', 'type' => ['kind' => 'resource', 'fqcn' => OrphanResource::class], 'optional' => false],
            ]]],
        ];

        $output = $this->typeScriptRenderer->renderResource($resource, self::MODELS, [], self::RESOURCES);

        $this->assertStringNotContainsString('OrphanResource', $output);
        $this->assertStringContainsString('  orphan: unknown;', $output);
    }

    public function test_page_prop_of_a_model_with_no_type_is_unknown(): void
    {
        $pages = [['component' => 'Legacy/Show', 'props' => ['legacy' => LegacyModel::class, 'user' => User::class], 'optional' => []]];

        $output = $this->typeScriptRenderer->renderPages($pages, self::MODELS, [], self::RESOURCES);

        $this->assertStringNotContainsString('LegacyModel', $output);
        $this->assertStringContainsString("'Legacy/Show': { legacy: unknown; user: User };", $output);
        $this->assertSame(
            ['a root file references '.LegacyModel::class.', which gets no generated type (ignored, outside the configured paths, or skipped): emitted as unknown'],
            $this->warnings,
        );
    }

    public function test_each_warning_is_given_once_per_run(): void
    {
        $pages = [
            ['component' => 'A', 'props' => ['legacy' => LegacyModel::class], 'optional' => []],
            ['component' => 'B', 'props' => ['legacy' => LegacyModel::class], 'optional' => []],
        ];

        $this->typeScriptRenderer->renderPages($pages, self::MODELS, [], self::RESOURCES);
        $this->assertCount(1, $this->warnings);

        $this->typeScriptRenderer->onWarn(function (string $message): void {
            $this->warnings[] = $message;
        });
        $this->typeScriptRenderer->renderPages($pages, self::MODELS, [], self::RESOURCES);
        $this->assertCount(2, $this->warnings);
    }
}
