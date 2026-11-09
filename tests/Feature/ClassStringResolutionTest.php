<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserResource;
use App\Models\Post;
use App\Models\User;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\TestCase;

/**
 * Class-strings in attribute shapes resolve to the generated type, imported
 * by a path relative to the file being written (#75).
 */
final class ClassStringResolutionTest extends TestCase
{
    private TypeScriptRenderer $typeScriptRenderer;

    private array $models = [['name' => 'User', 'fqcn' => User::class], ['name' => 'Post', 'fqcn' => Post::class]];

    private array $enums = [['name' => 'PostStatus', 'fqcn' => PostStatus::class]];

    private array $resources = [['name' => 'UserResource', 'fqcn' => UserResource::class], ['name' => 'PostResource', 'fqcn' => PostResource::class]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->typeScriptRenderer = new TypeScriptRenderer;
    }

    public function test_resource_shape_imports_models_and_enums_from_the_parent_directory(): void
    {
        $output = $this->typeScriptRenderer->renderResource([
            'name' => 'PostResource',
            'fqcn' => PostResource::class,
            'shape' => ['kind' => 'shape', 'fields' => [
                'author' => User::class,
                'status' => PostStatus::class,
                'editor' => UserResource::class.' | null',
                'related' => Post::class.'[]',
                'self' => PostResource::class,
            ]],
        ], $this->models, $this->enums, $this->resources);

        $this->assertStringContainsString("import type { User } from '../models';", $output);
        $this->assertStringContainsString("import type { Post } from '../models';", $output);
        $this->assertStringContainsString("import type { PostStatus } from '../enums';", $output);
        $this->assertStringContainsString("import type { UserResource } from './UserResource';", $output);
        $this->assertStringNotContainsString("from './models'", $output);
        $this->assertStringNotContainsString("from './enums'", $output);
        $this->assertStringNotContainsString('import type { PostResource }', $output);
        $this->assertStringContainsString('  author: User;', $output);
        $this->assertStringContainsString('  status: PostStatus;', $output);
        $this->assertStringContainsString('  editor: UserResource | null;', $output);
        $this->assertStringContainsString('  related: Post[];', $output);
        $this->assertStringContainsString('  self: PostResource;', $output);
    }

    public function test_resource_extend_imports_from_the_parent_directory(): void
    {
        $output = $this->typeScriptRenderer->renderResource([
            'name' => 'UserResource',
            'fqcn' => UserResource::class,
            'shape' => ['kind' => 'model', 'model' => User::class, 'omit' => [], 'extend' => [
                'latest_status' => PostStatus::class,
                'posts' => Post::class.'[]',
            ]],
        ], $this->models, $this->enums, $this->resources);

        $this->assertStringContainsString("import type { PostStatus } from '../enums';", $output);
        $this->assertStringContainsString("import type { Post } from '../models';", $output);
        $this->assertStringContainsString('latest_status: PostStatus; posts: Post[]', $output);
    }

    public function test_page_props_resolve_resource_class_strings(): void
    {
        $output = $this->typeScriptRenderer->renderPages([[
            'component' => 'Posts/Show',
            'props' => ['post' => PostResource::class, 'author' => User::class, 'status' => PostStatus::class.' | null'],
            'optional' => [],
            'source' => 'X::show',
        ]], $this->models, $this->enums, $this->resources);

        $this->assertStringContainsString("import type { PostResource } from './resources';", $output);
        $this->assertStringContainsString("import type { User } from './models';", $output);
        $this->assertStringContainsString("import type { PostStatus } from './enums';", $output);
        $this->assertStringContainsString("'Posts/Show': { post: PostResource; author: User; status: PostStatus | null };", $output);
        $this->assertStringNotContainsString('\\', $output);
    }

    public function test_broadcast_payload_resolves_resource_class_strings(): void
    {
        $output = $this->typeScriptRenderer->renderBroadcasting([[
            'event_class' => 'X',
            'broadcast_name' => 'PostUpdated',
            'channels' => [['type' => 'public', 'name' => 'posts']],
            'payload' => ['post' => PostResource::class],
        ]], $this->models, $this->enums, $this->resources);

        $this->assertStringContainsString("import type { PostResource } from './resources';", $output);
        $this->assertStringContainsString("'PostUpdated': { post: PostResource }", $output);
        $this->assertStringNotContainsString('\\', $output);
    }

    public function test_model_override_class_strings_resolve(): void
    {
        $output = $this->typeScriptRenderer->renderModelFile([
            'name' => 'Post',
            'fqcn' => Post::class,
            'columns' => [
                ['name' => 'owner', 'type' => User::class.' | null', 'nullable' => false],
                ['name' => 'status_history', 'type' => '\\'.PostStatus::class.'[]', 'nullable' => false],
                ['name' => 'parent', 'type' => Post::class, 'nullable' => true],
            ],
            'relationships' => [],
            'assignable_columns' => [],
        ], $this->enums, $this->models, false, []);

        $this->assertStringContainsString("import type { User } from './User';", $output);
        $this->assertStringContainsString("import type { PostStatus } from '../enums';", $output);
        $this->assertStringNotContainsString("import type { Post } from './Post';", $output);
        $this->assertStringContainsString('  owner: User | null;', $output);
        $this->assertStringContainsString('  status_history: PostStatus[];', $output);
        $this->assertStringContainsString('  parent: Post | null;', $output);
    }

    public function test_request_override_class_strings_resolve(): void
    {
        $output = $this->typeScriptRenderer->renderRequest([
            'name' => 'StorePostRequest',
            'fields' => [
                ['name' => 'author', 'type' => User::class, 'required' => true, 'nullable' => false],
                ['name' => 'status', 'type' => PostStatus::class, 'required' => false, 'nullable' => true],
            ],
        ], $this->enums);

        $this->assertStringContainsString("import type { User } from '../models';", $output);
        $this->assertStringContainsString("import type { PostStatus } from '../enums';", $output);
        $this->assertStringContainsString('  author: User;', $output);
        $this->assertStringContainsString('  status?: PostStatus | null;', $output);
    }

    public function test_plain_type_strings_are_left_alone(): void
    {
        $output = $this->typeScriptRenderer->renderPages([[
            'component' => 'Plain',
            'props' => ['a' => 'string | null', 'b' => 'Record<string, number>', 'c' => "'x' | 'y'", 'd' => 'NotAClass'],
            'optional' => [],
            'source' => 'X::y',
        ]], [], [], []);

        $this->assertStringContainsString("'Plain': { a: string | null; b: Record<string, number>; c: 'x' | 'y'; d: NotAClass };", $output);
        $this->assertStringNotContainsString('import', $output);
    }
}
