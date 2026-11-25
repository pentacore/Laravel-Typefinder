<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Tag;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Pentacore\Typefinder\Resolvers\PivotResolver;
use Tests\Fixtures\Pivots\Thing;
use Tests\TestCase;

/** Pivot types come from the pivot table, the `using` class and `as()` (#145). */
final class PivotSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('things', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('label_thing', function (Blueprint $table): void {
            $table->uuid('label_id');
            $table->unsignedBigInteger('thing_id');
            $table->integer('quantity');
            $table->integer('is_primary');
            $table->string('status');
            $table->text('note')->nullable();
        });
    }

    public function test_pivot_columns_are_typed_from_the_table_and_the_using_class(): void
    {
        $model = new ModelExtractor(new ColumnTypeResolver, new CastTypeResolver)->extract(Thing::class);
        $pivots = new PivotResolver()->resolve([$model]);

        $this->assertSame([
            ['name' => 'thing_id', 'type' => 'number', 'nullable' => false],
            ['name' => 'label_id', 'type' => 'string', 'nullable' => false],
            ['name' => 'quantity', 'type' => 'number', 'nullable' => false],
            ['name' => 'is_primary', 'type' => 'boolean', 'nullable' => false],
            ['name' => 'status', 'type' => ['enum' => PostStatus::class], 'nullable' => false],
            ['name' => 'note', 'type' => 'string', 'nullable' => true],
        ], $pivots[0]['columns']);

        $output = new TypeScriptRenderer()->renderPivot($pivots[0]);
        $this->assertStringContainsString("import type { PostStatus } from '../enums';", $output);
        $this->assertStringContainsString('  status: PostStatus;', $output);
        $this->assertStringContainsString('  note: string | null;', $output);
    }

    public function test_the_relation_uses_the_pivot_accessor(): void
    {
        $model = new ModelExtractor(new ColumnTypeResolver, new CastTypeResolver)->extract(Thing::class);
        $output = new TypeScriptRenderer()->renderModel($model, [], [$model, ['name' => 'Tag', 'fqcn' => Tag::class]]);

        $this->assertStringContainsString('  labels?: (Tag & { tagging: LabelThingPivot })[];', $output);
    }
}
