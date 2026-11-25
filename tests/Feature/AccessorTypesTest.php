<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\User;
use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Tests\Fixtures\Models\AccessorTypes;
use Tests\TestCase;

/** Appended accessors typed by their PHP return types (#148). */
final class AccessorTypesTest extends TestCase
{
    public function test_accessor_types_come_from_php_return_types(): void
    {
        $columns = collect(new ModelExtractor(new ColumnTypeResolver, new CastTypeResolver)->extract(AccessorTypes::class)['columns'])
            ->keyBy('name')
            ->map(fn (array $column): array => [$column['type'], $column['nullable']])
            ->all();

        $this->assertSame(['string', false], $columns['full_name']);
        $this->assertSame(['unknown[] | Record<string, unknown>', false], $columns['shout']);
        $this->assertSame([PostStatus::class, false], $columns['status']);
        $this->assertSame(["'On' | 'Off'", false], $columns['mode']);
        $this->assertSame([User::class, false], $columns['owner']);
        $this->assertSame(['string | number', false], $columns['either']);
        $this->assertSame([User::class, true], $columns['maybe_owner']);
    }

    public function test_enum_and_model_accessors_render_as_their_generated_types(): void
    {
        $model = new ModelExtractor(new ColumnTypeResolver, new CastTypeResolver)->extract(AccessorTypes::class);
        $user = ['name' => 'User', 'fqcn' => User::class];
        $output = new TypeScriptRenderer()->renderModel($model, [['name' => 'PostStatus', 'fqcn' => PostStatus::class]], [$model, $user]);

        $this->assertStringContainsString('  status: PostStatus;', $output);
        $this->assertStringContainsString('  owner: User;', $output);
        $this->assertStringContainsString('  maybe_owner: User | null;', $output);
        $this->assertStringContainsString("import type { User } from './User';", $output);
    }
}
