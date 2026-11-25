<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Tests\Fixtures\Models\OverrideFields;
use Tests\TestCase;

/** #[TypefinderOverrides] adds fields and replaces relations on a model (#144). */
final class ModelOverrideFieldsTest extends TestCase
{
    public function test_overrides_add_fields_and_replace_relations(): void
    {
        $model = new ModelExtractor(new ColumnTypeResolver, new CastTypeResolver)->extract(OverrideFields::class);
        $output = new TypeScriptRenderer()->renderModelFile($model, [], [$model], true, []);
        $readShape = (string) strstr($output, 'export type OverrideFieldsCreate', true);

        $this->assertStringContainsString("  name: 'a' | 'b';", $readShape);
        $this->assertStringContainsString('  full_title: string;', $readShape);
        $this->assertStringContainsString('  children_count: number;', $readShape);
        $this->assertStringContainsString('  children?: string[];', $readShape);
        $this->assertStringNotContainsString('Tag', $output);
        $this->assertStringNotContainsString('secret_note', $output);

        // Added fields are read-only.
        $this->assertStringNotContainsString('full_title', strstr($output, 'export type OverrideFieldsCreate'));
    }
}
