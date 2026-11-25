<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\User;
use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Tests\Fixtures\Models\HiddenFillable;
use Tests\Fixtures\Models\RelationEdgeCases;
use Tests\TestCase;

/** Hidden columns and relations, untyped and failing relations (#143, #149, #192). */
final class ModelVisibilityAndRelationsTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    public function test_an_untyped_method_that_builds_a_relation_is_typed(): void
    {
        $relations = collect($this->extractor()->extract(RelationEdgeCases::class)['relationships'])->keyBy('name');

        $this->assertSame('many', $relations['untyped']['type']);
        $this->assertSame(Tag::class, $relations['untyped']['related']);
    }

    public function test_a_relation_that_throws_is_reported(): void
    {
        $relations = collect($this->extractor()->extract(RelationEdgeCases::class)['relationships'])->keyBy('name');

        $this->assertArrayNotHasKey('broken', $relations->all());
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString(RelationEdgeCases::class.'::broken(): building the relation threw', $this->warnings[0]);
    }

    public function test_a_hidden_relation_is_left_out(): void
    {
        $relations = collect($this->extractor()->extract(RelationEdgeCases::class)['relationships'])->keyBy('name');

        $this->assertArrayNotHasKey('secretOwner', $relations->all());
        $this->assertSame(User::class, $relations['owner']['related']);
    }

    public function test_a_relation_not_in_visible_is_left_out(): void
    {
        $model = new class extends RelationEdgeCases
        {
            protected $hidden = [];

            protected $visible = ['id', 'secretOwner'];
        };

        $relations = array_column($this->extractor()->extract($model::class)['relationships'], 'name');

        $this->assertSame(['secretOwner'], $relations);
    }

    public function test_a_hidden_fillable_column_stays_in_the_write_shapes(): void
    {
        $model = $this->extractor()->extract(HiddenFillable::class);

        $this->assertNotContains('password', array_column($model['columns'], 'name'));
        $this->assertContains('password', array_column($model['assignable_columns'], 'name'));
        $this->assertNotContains('remember_token', array_column($model['assignable_columns'], 'name'));

        $output = new TypeScriptRenderer()->renderModelFile($model, [], [$model], true, []);

        $this->assertMatchesRegularExpression('/export type HiddenFillableCreate = \{\n  name: string;\n  password: string;\n\};/', $output);
        $this->assertStringNotContainsString('remember_token', $output);
    }

    private function extractor(): ModelExtractor
    {
        return new ModelExtractor(new ColumnTypeResolver, new CastTypeResolver, function (string $message): void {
            $this->warnings[] = $message;
        });
    }
}
