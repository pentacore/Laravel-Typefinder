<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\AdminUserResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
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
}
