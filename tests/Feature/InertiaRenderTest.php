<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\User;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\TestCase;

final class InertiaRenderTest extends TestCase
{
    public function test_renders_model_and_scalar_props(): void
    {
        $renderer = new TypeScriptRenderer;

        $pages = [[
            'component' => 'Users/Show',
            'props' => ['user' => User::class, 'canEdit' => 'boolean'],
            'optional' => [],
            'source' => 'App\\Http\\Controllers\\UserController::show',
        ]];

        $allModels = [['name' => 'User', 'fqcn' => User::class]];

        $output = $renderer->renderPages($pages, $allModels, []);

        $this->assertStringContainsString("import type { User } from './models';", $output);
        $this->assertStringContainsString('export type PageProps = {', $output);
        $this->assertStringContainsString("'Users/Show': { user: User; canEdit: boolean };", $output);
        $this->assertStringContainsString('export type PageName = keyof PageProps;', $output);
    }

    public function test_renders_optional_props(): void
    {
        $renderer = new TypeScriptRenderer;

        $pages = [[
            'component' => 'Dashboard',
            'props' => ['greeting' => 'string', 'stats' => 'unknown'],
            'optional' => ['stats'],
            'source' => 'x::y',
        ]];

        $output = $renderer->renderPages($pages, [], []);

        $this->assertStringContainsString("'Dashboard': { greeting: string; stats?: unknown };", $output);
    }

    public function test_resolves_enum_class_string(): void
    {
        $renderer = new TypeScriptRenderer;

        $pages = [[
            'component' => 'Posts/Filter',
            'props' => ['status' => PostStatus::class],
            'optional' => [],
            'source' => 'x::y',
        ]];

        $allEnums = [['name' => 'PostStatus', 'fqcn' => PostStatus::class]];

        $output = $renderer->renderPages($pages, [], $allEnums);

        $this->assertStringContainsString("import type { PostStatus } from './enums';", $output);
        $this->assertStringContainsString("'Posts/Filter': { status: PostStatus };", $output);
    }

    public function test_passes_through_unknown_strings(): void
    {
        $renderer = new TypeScriptRenderer;

        $pages = [[
            'component' => 'Thing',
            'props' => ['x' => 'User[]', 'y' => 'string | null'],
            'optional' => [],
            'source' => 'x::y',
        ]];

        $output = $renderer->renderPages($pages, [], []);

        $this->assertStringContainsString("'Thing': { x: User[]; y: string | null };", $output);
    }
}
