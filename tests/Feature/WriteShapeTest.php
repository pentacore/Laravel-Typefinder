<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\TestCase;

final class WriteShapeTest extends TestCase
{
    public function test_render_model_create_omits_server_filled_and_relationships(): void
    {
        $renderer = new TypeScriptRenderer;

        $model = [
            'name' => 'User',
            'fqcn' => User::class,
            'columns' => [
                ['name' => 'id', 'type' => 'number', 'nullable' => false, 'is_primary' => true, 'is_server_filled' => true],
                ['name' => 'name', 'type' => 'string', 'nullable' => false, 'is_primary' => false, 'is_server_filled' => false],
                ['name' => 'bio', 'type' => 'string', 'nullable' => true, 'is_primary' => false, 'is_server_filled' => false],
                ['name' => 'created_at', 'type' => 'string', 'nullable' => true, 'is_primary' => false, 'is_server_filled' => true],
            ],
            'relationships' => [['name' => 'posts', 'type' => 'many', 'related' => Post::class, 'relationType' => '']],
            'assignable_columns' => [
                ['name' => 'name', 'type' => 'string', 'nullable' => false, 'is_primary' => false, 'is_server_filled' => false],
                ['name' => 'bio', 'type' => 'string', 'nullable' => true, 'is_primary' => false, 'is_server_filled' => false],
            ],
        ];

        $output = $renderer->renderModelCreate($model, [], []);

        $this->assertStringContainsString('export type UserCreate = {', $output);
        $this->assertStringContainsString('  name: string;', $output);
        $this->assertStringContainsString('  bio?: string | null;', $output);
        $this->assertStringNotContainsString('id:', $output);
        $this->assertStringNotContainsString('created_at', $output);
        $this->assertStringNotContainsString('posts', $output);
    }

    public function test_render_model_update_omits_immutable_and_makes_all_optional(): void
    {
        $renderer = new TypeScriptRenderer;

        $model = [
            'name' => 'User',
            'fqcn' => User::class,
            'columns' => [
                ['name' => 'id', 'type' => 'number', 'nullable' => false, 'is_primary' => true, 'is_server_filled' => true],
                ['name' => 'name', 'type' => 'string', 'nullable' => false, 'is_primary' => false, 'is_server_filled' => false],
                ['name' => 'created_at', 'type' => 'string', 'nullable' => true, 'is_primary' => false, 'is_server_filled' => true],
            ],
            'relationships' => [],
            'assignable_columns' => [
                ['name' => 'name', 'type' => 'string', 'nullable' => false, 'is_primary' => false, 'is_server_filled' => false],
            ],
        ];

        $output = $renderer->renderModelUpdate($model, [], [], ['id', 'created_at', 'updated_at']);

        $this->assertStringContainsString('export type UserUpdate = {', $output);
        $this->assertStringContainsString('  name?: string;', $output);
        $this->assertStringNotContainsString('id:', $output);
        $this->assertStringNotContainsString('created_at', $output);
    }
}
