<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\PostResource;
use App\Http\Resources\UserResource;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\TestCase;

/**
 * Inertia sends a `JsonResource` page prop through `toResponse()`, so the
 * page gets it in the resource's wrapper (#241).
 */
final class PageResourceWrappingTest extends TestCase
{
    private array $models = [['name' => 'User', 'fqcn' => User::class, 'columns' => [['name' => 'id'], ['name' => 'data']], 'relationships' => []], ['name' => 'Post', 'fqcn' => Post::class]];

    protected function tearDown(): void
    {
        JsonResource::wrap('data');
        JsonResource::$forceWrapping = false;

        parent::tearDown();
    }

    public function test_a_resource_prop_is_wrapped_in_data(): void
    {
        $output = $this->render(['post' => PostResource::class, 'editor' => UserResource::class.' | null']);

        $this->assertStringContainsString("import type { WrappedResource } from './helpers';", $output);
        $this->assertStringContainsString("import type { PostResource, UserResource } from './resources';", $output);
        $this->assertStringContainsString("'Posts/Show': { post: WrappedResource<PostResource>; editor: WrappedResource<UserResource> | null };", $output);
    }

    public function test_a_resource_prop_is_bare_without_wrapping(): void
    {
        JsonResource::withoutWrapping();

        $output = $this->render(['post' => PostResource::class]);

        $this->assertStringContainsString("'Posts/Show': { post: PostResource };", $output);
        $this->assertStringNotContainsString('WrappedResource', $output);
    }

    public function test_a_resource_prop_uses_a_custom_wrapper_key(): void
    {
        JsonResource::wrap('post');

        $output = $this->render(['post' => PostResource::class]);

        $this->assertStringContainsString("'Posts/Show': { post: { post: PostResource } };", $output);
        $this->assertStringNotContainsString('WrappedResource', $output);
    }

    public function test_a_resource_whose_shape_has_the_wrapper_key_is_not_wrapped_again(): void
    {
        $output = $this->render(['post' => PostResource::class], [
            ['name' => 'PostResource', 'fqcn' => PostResource::class, 'shape' => ['kind' => 'shape', 'fields' => ['data' => 'string']]],
        ]);

        $this->assertStringContainsString("'Posts/Show': { post: PostResource };", $output);
    }

    public function test_a_model_resource_whose_model_has_the_wrapper_key_is_not_wrapped_again(): void
    {
        $output = $this->render(['user' => UserResource::class], [
            ['name' => 'UserResource', 'fqcn' => UserResource::class, 'shape' => ['kind' => 'model', 'model' => User::class, 'omit' => [], 'extend' => []]],
        ]);

        $this->assertStringContainsString("'Posts/Show': { user: UserResource };", $output);
    }

    public function test_an_optional_or_omitted_wrapper_key_still_wraps(): void
    {
        $output = $this->render(['post' => PostResource::class, 'user' => UserResource::class], [
            ['name' => 'PostResource', 'fqcn' => PostResource::class, 'shape' => ['kind' => 'shape', 'fields' => ['data?' => 'string']]],
            ['name' => 'UserResource', 'fqcn' => UserResource::class, 'shape' => ['kind' => 'model', 'model' => User::class, 'omit' => ['data'], 'extend' => []]],
        ]);

        $this->assertStringContainsString("'Posts/Show': { post: WrappedResource<PostResource>; user: WrappedResource<UserResource> };", $output);
    }

    public function test_forced_wrapping_wraps_a_shape_with_the_wrapper_key(): void
    {
        JsonResource::$forceWrapping = true;

        $output = $this->render(['post' => PostResource::class], [
            ['name' => 'PostResource', 'fqcn' => PostResource::class, 'shape' => ['kind' => 'shape', 'fields' => ['data' => 'string']]],
        ]);

        $this->assertStringContainsString("'Posts/Show': { post: WrappedResource<PostResource> };", $output);
    }

    public function test_a_resource_in_a_list_or_generic_is_left_as_written(): void
    {
        $output = $this->render([
            'posts' => PostResource::class.'[]',
            'pinned' => 'Array<'.PostResource::class.'>',
        ]);

        $this->assertStringContainsString("'Posts/Show': { posts: PostResource[]; pinned: Array<PostResource> };", $output);
        $this->assertStringNotContainsString('WrappedResource', $output);
    }

    public function test_a_helper_written_in_a_prop_is_imported(): void
    {
        $output = $this->render([
            'posts' => 'WrappedResourceCollection<'.PostResource::class.'>',
            'page' => 'PaginatedResourceCollection<'.PostResource::class.'> | null',
            'note' => 'string',
            'shadow' => 'App\\Support\\WrappedResource',
        ]);

        $this->assertStringContainsString("import type { PaginatedResourceCollection, WrappedResourceCollection } from './helpers';", $output);
        $this->assertStringContainsString("'Posts/Show': { posts: WrappedResourceCollection<PostResource>; page: PaginatedResourceCollection<PostResource> | null; note: string; shadow: App\\Support\\WrappedResource };", $output);
    }

    public function test_models_and_enums_are_not_wrapped(): void
    {
        $output = $this->render(['author' => User::class]);

        $this->assertStringContainsString("'Posts/Show': { author: User };", $output);
        $this->assertStringNotContainsString('WrappedResource', $output);
    }

    /**
     * @param  array<string, string>  $props
     * @param  ?list<array>  $resources
     */
    private function render(array $props, ?array $resources = null): string
    {
        $resources ??= [['name' => 'UserResource', 'fqcn' => UserResource::class], ['name' => 'PostResource', 'fqcn' => PostResource::class]];

        return new TypeScriptRenderer()->renderPages([[
            'component' => 'Posts/Show',
            'props' => $props,
            'optional' => [],
            'source' => 'X::show',
        ]], $this->models, [], $resources, ['kind' => 'object', 'fields' => [], 'open' => false]);
    }
}
