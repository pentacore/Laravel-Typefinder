<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Http\Requests\StorePostRequest;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\TestCase;

class TypeScriptRendererTest extends TestCase
{
    private TypeScriptRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new TypeScriptRenderer;
    }

    public function test_renders_enum_type(): void
    {
        $enum = [
            'name' => 'PostStatus',
            'fqcn' => PostStatus::class,
            'backingType' => 'string',
            'values' => ['draft', 'published', 'archived'],
        ];

        $output = $this->renderer->renderEnum($enum);

        $this->assertStringContainsString("export type PostStatus = 'draft' | 'published' | 'archived';", $output);
    }

    public function test_renders_integer_backed_enum(): void
    {
        $enum = [
            'name' => 'Priority',
            'fqcn' => 'App\\Enums\\Priority',
            'backingType' => 'int',
            'values' => [1, 2, 3],
        ];

        $output = $this->renderer->renderEnum($enum);

        $this->assertStringContainsString('export type Priority = 1 | 2 | 3;', $output);
    }

    public function test_renders_simple_model_type(): void
    {
        $model = [
            'name' => 'Role',
            'fqcn' => Role::class,
            'columns' => [
                ['name' => 'id', 'type' => 'number', 'nullable' => false],
                ['name' => 'name', 'type' => 'string', 'nullable' => false],
                ['name' => 'created_at', 'type' => 'string', 'nullable' => true],
                ['name' => 'updated_at', 'type' => 'string', 'nullable' => true],
            ],
            'relationships' => [],
        ];

        $output = $this->renderer->renderModel($model, [], []);

        $this->assertStringContainsString('export type Role = {', $output);
        $this->assertStringContainsString('id: number;', $output);
        $this->assertStringContainsString('name: string;', $output);
        $this->assertStringContainsString('created_at: string | null;', $output);
    }

    public function test_renders_model_with_enum_cast(): void
    {
        $model = [
            'name' => 'Post',
            'fqcn' => Post::class,
            'columns' => [
                ['name' => 'id', 'type' => 'number', 'nullable' => false],
                ['name' => 'status', 'type' => ['enum' => PostStatus::class], 'nullable' => false],
            ],
            'relationships' => [],
        ];

        $enums = [
            ['name' => 'PostStatus', 'fqcn' => PostStatus::class, 'backingType' => 'string', 'values' => ['draft', 'published', 'archived']],
        ];

        $output = $this->renderer->renderModel($model, $enums, []);

        $this->assertStringContainsString("import type { PostStatus } from '../enums';", $output);
        $this->assertStringContainsString('status: PostStatus;', $output);
    }

    public function test_renders_model_with_relationships(): void
    {
        $model = [
            'name' => 'User',
            'fqcn' => User::class,
            'columns' => [
                ['name' => 'id', 'type' => 'number', 'nullable' => false],
            ],
            'relationships' => [
                ['name' => 'posts', 'type' => 'many', 'related' => Post::class, 'relationType' => 'HasMany'],
                ['name' => 'roles', 'type' => 'manyWithPivot', 'related' => Role::class, 'relationType' => 'BelongsToMany', 'pivot' => [
                    'table' => 'role_user',
                    'foreignKey' => 'user_id',
                    'relatedKey' => 'role_id',
                    'withPivot' => ['assigned_at'],
                    'withTimestamps' => true,
                ]],
            ],
        ];

        $allModels = [
            ['name' => 'Post', 'fqcn' => Post::class],
            ['name' => 'Role', 'fqcn' => Role::class],
        ];

        $output = $this->renderer->renderModel($model, [], $allModels);

        $this->assertStringContainsString("import type { Post } from './Post';", $output);
        $this->assertStringContainsString("import type { Role } from './Role';", $output);
        $this->assertStringContainsString("import type { RoleUserPivot } from '../pivots';", $output);
        $this->assertStringContainsString('posts?: Post[];', $output);
        $this->assertStringContainsString('roles?: (Role & { pivot: RoleUserPivot })[];', $output);
    }

    public function test_renders_morph_to_with_generics(): void
    {
        $model = [
            'name' => 'Comment',
            'fqcn' => Comment::class,
            'columns' => [
                ['name' => 'id', 'type' => 'number', 'nullable' => false],
                ['name' => 'commentable_id', 'type' => 'number', 'nullable' => false],
                ['name' => 'commentable_type', 'type' => 'string', 'nullable' => false],
            ],
            'relationships' => [
                ['name' => 'commentable', 'type' => 'morphTo', 'related' => Model::class, 'relationType' => 'MorphTo', 'morphTargets' => [Post::class]],
            ],
        ];

        $allModels = [
            ['name' => 'Post', 'fqcn' => Post::class],
        ];

        $output = $this->renderer->renderModel($model, [], $allModels);

        $this->assertStringContainsString('export type Comment<T extends Post = Post> = {', $output);
        $this->assertStringContainsString('commentable?: T | null;', $output);
    }

    public function test_renders_request_type(): void
    {
        $request = [
            'name' => 'StorePostRequest',
            'fqcn' => StorePostRequest::class,
            'fields' => [
                ['name' => 'title', 'type' => 'string', 'required' => true, 'nullable' => false],
                ['name' => 'body', 'type' => 'string', 'required' => true, 'nullable' => false],
                ['name' => 'status', 'type' => ['enum' => PostStatus::class], 'required' => true, 'nullable' => false],
                ['name' => 'tags', 'type' => 'string[]', 'required' => false, 'nullable' => true],
                ['name' => 'category', 'type' => ['in' => ['tech', 'science', 'art']], 'required' => true, 'nullable' => false],
            ],
        ];

        $enums = [
            ['name' => 'PostStatus', 'fqcn' => PostStatus::class, 'backingType' => 'string', 'values' => ['draft']],
        ];

        $output = $this->renderer->renderRequest($request, $enums);

        $this->assertStringContainsString('export type StorePostRequest = {', $output);
        $this->assertStringContainsString('title: string;', $output);
        $this->assertStringContainsString('body: string;', $output);
        $this->assertStringContainsString('status: PostStatus;', $output);
        $this->assertStringContainsString('tags?: string[] | null;', $output);
        $this->assertStringContainsString("category: 'tech' | 'science' | 'art';", $output);
    }

    public function test_renders_request_with_nested_object(): void
    {
        $request = [
            'name' => 'StoreUserRequest',
            'fqcn' => 'App\\Http\\Requests\\StoreUserRequest',
            'fields' => [
                ['name' => 'address', 'type' => 'object', 'required' => true, 'nullable' => false, 'children' => [
                    ['name' => 'street', 'type' => 'string', 'required' => true, 'nullable' => false],
                    ['name' => 'city', 'type' => 'string', 'required' => true, 'nullable' => false],
                    ['name' => 'zip', 'type' => 'string', 'required' => false, 'nullable' => true],
                ]],
            ],
        ];

        $output = $this->renderer->renderRequest($request, [], false);

        $this->assertStringContainsString('address: {', $output);
        $this->assertStringContainsString('street: string;', $output);
        $this->assertStringContainsString('zip?: string | null;', $output);
    }

    public function test_renders_request_with_extracted_nested(): void
    {
        $request = [
            'name' => 'StoreUserRequest',
            'fqcn' => 'App\\Http\\Requests\\StoreUserRequest',
            'fields' => [
                ['name' => 'address', 'type' => 'object', 'required' => true, 'nullable' => false, 'children' => [
                    ['name' => 'street', 'type' => 'string', 'required' => true, 'nullable' => false],
                    ['name' => 'city', 'type' => 'string', 'required' => true, 'nullable' => false],
                ]],
            ],
        ];

        $output = $this->renderer->renderRequest($request, [], true);

        $this->assertStringContainsString('export type StoreUserRequestAddress = {', $output);
        $this->assertStringContainsString('address: StoreUserRequestAddress;', $output);
    }

    public function test_renders_pivot_type(): void
    {
        $pivot = [
            'name' => 'RoleUserPivot',
            'columns' => [
                ['name' => 'user_id', 'type' => 'number'],
                ['name' => 'role_id', 'type' => 'number'],
                ['name' => 'assigned_at', 'type' => 'string', 'nullable' => true],
            ],
            'withTimestamps' => true,
        ];

        $output = $this->renderer->renderPivot($pivot);

        $this->assertStringContainsString('export type RoleUserPivot = {', $output);
        $this->assertStringContainsString('user_id: number;', $output);
        $this->assertStringContainsString('role_id: number;', $output);
        $this->assertStringContainsString('assigned_at: string | null;', $output);
        $this->assertStringContainsString('created_at: string | null;', $output);
        $this->assertStringContainsString('updated_at: string | null;', $output);
    }

    public function test_renders_barrel_index(): void
    {
        $files = ['Post', 'User', 'Comment'];

        $output = $this->renderer->renderBarrelIndex($files);

        $this->assertStringContainsString("export type { Post } from './Post';", $output);
        $this->assertStringContainsString("export type { User } from './User';", $output);
        $this->assertStringContainsString("export type { Comment } from './Comment';", $output);
    }

    public function test_renders_top_level_barrel(): void
    {
        $categories = ['models', 'enums', 'requests', 'pivots'];

        $output = $this->renderer->renderTopLevelBarrel($categories);

        $this->assertStringContainsString("export type * from './models';", $output);
        $this->assertStringContainsString("export type * from './enums';", $output);
    }
}
