<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Tests\Fixtures\Models\Issue123Quest;
use Tests\TestCase;

/**
 * Issue #123: a column the database or the model fills in when an insert
 * leaves it out is optional on `{Model}Create`. Its read type stays as it is.
 */
final class CreateShapeDefaultsTest extends TestCase
{
    public function test_extraction_records_which_columns_have_a_default(): void
    {
        $defaults = array_column($this->extract()['columns'], 'has_default', 'name');

        $this->assertTrue($defaults['completed'], 'database default');
        $this->assertTrue($defaults['title'], 'model $attributes default');
        $this->assertFalse($defaults['party_id'], 'a null model default fills nothing in');
        $this->assertFalse($defaults['description']);
    }

    public function test_a_column_with_a_default_is_optional_on_create(): void
    {
        $output = new TypeScriptRenderer()->renderModelCreate($this->extract(), [], []);

        $this->assertStringContainsString("export type Issue123QuestCreate = {\n", $output);
        $this->assertStringContainsString("  party_id: string;\n", $output);
        $this->assertStringContainsString("  title?: string;\n", $output);
        $this->assertStringContainsString("  completed?: boolean;\n", $output);
        $this->assertStringContainsString("  description?: string | null;\n", $output);
    }

    public function test_a_column_with_a_default_stays_required_on_the_read_shape(): void
    {
        $output = new TypeScriptRenderer()->renderModel($this->extract(), [], []);

        $this->assertStringContainsString("  title: string;\n", $output);
        $this->assertStringContainsString("  completed: boolean;\n", $output);
    }

    /**
     * @return array<string, mixed>
     */
    private function extract(): array
    {
        return new ModelExtractor(new ColumnTypeResolver, new CastTypeResolver)->extract(Issue123Quest::class);
    }
}
