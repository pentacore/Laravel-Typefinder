<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Tests\Fixtures\Models\DocumentedCastQuest;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

/** A class-level @property type narrows an array / JSON cast (#193). */
final class ModelPropertyDocTest extends TestCase
{
    public function test_property_docs_type_array_and_json_casts(): void
    {
        config([
            'typefinder.enums.paths' => [workbench_path('app/Enums')],
            'typefinder.resources.paths' => [workbench_path('app/Http/Resources')],
            'typefinder.models.paths' => [workbench_path('app/Models')],
        ]);

        $model = new ModelExtractor(new ColumnTypeResolver, new CastTypeResolver)->extract(DocumentedCastQuest::class);
        $output = new TypeScriptRenderer()->renderModel($model, [['name' => 'PostStatus', 'fqcn' => PostStatus::class]], [$model]);

        $this->assertStringContainsString('  rewards: { name: string; required: boolean }[] | null;', $output);
        $this->assertStringContainsString('  description: PostStatus[] | null;', $output);
        $this->assertStringContainsString("import type { PostStatus } from '../enums';", $output);
        // A model is imported from its own file, a resource from the resources directory.
        $this->assertStringContainsString('  completed_at: User[] | null;', $output);
        $this->assertStringContainsString("import type { User } from './User';", $output);
        $this->assertStringContainsString('  party_id: UserResource;', $output);
        $this->assertStringContainsString("import type { UserResource } from '../resources';", $output);
        // A cast with a precise type keeps it.
        $this->assertStringContainsString('  title: string;', $output);
    }
}
