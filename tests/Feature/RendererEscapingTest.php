<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\QuirkyThing;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\TestCase;

/**
 * Keys that are not identifiers are quoted and string literals are escaped
 * in every renderer (#135, #136, #137).
 */
final class RendererEscapingTest extends TestCase
{
    private TypeScriptRenderer $typeScriptRenderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->typeScriptRenderer = new TypeScriptRenderer;
    }

    public function test_string_literal_escapes_quotes_backslashes_and_line_breaks(): void
    {
        $this->assertSame("'it\\'s'", TypeScriptRenderer::stringLiteral("it's"));
        $this->assertSame("'App\\\\Models\\\\Post'", TypeScriptRenderer::stringLiteral(Post::class));
        $this->assertSame("'a\\nb'", TypeScriptRenderer::stringLiteral("a\nb"));
    }

    public function test_member_key_quotes_the_base_name_and_keeps_the_optional_marker_outside(): void
    {
        $this->assertSame("'x-y'?", TypeScriptRenderer::memberKey('x-y?'));
        $this->assertSame('plain?', TypeScriptRenderer::memberKey('plain?'));
        $this->assertSame("'first-name'", TypeScriptRenderer::memberKey('first-name'));
    }

    public function test_enum_values_are_escaped(): void
    {
        $enum = ['name' => 'Quote', 'backingType' => 'string', 'values' => ["it's", 'back\\slash'], 'cases' => [
            ['name' => 'A', 'value' => "it's"],
            ['name' => 'B', 'value' => 'back\\slash'],
        ]];

        $this->assertStringContainsString("export type Quote = 'it\\'s' | 'back\\\\slash';", $this->typeScriptRenderer->renderEnum($enum));
        $this->assertStringContainsString("    A: 'it\\'s',", $this->typeScriptRenderer->renderEnum($enum, emitValues: true));
    }

    public function test_enum_without_cases_is_never(): void
    {
        $enum = ['name' => 'Nothing', 'backingType' => 'string', 'values' => [], 'cases' => []];

        $this->assertStringContainsString('export type Nothing = never;', $this->typeScriptRenderer->renderEnum($enum));
    }

    public function test_model_columns_that_are_not_identifiers_are_quoted_in_every_shape(): void
    {
        $model = [
            'name' => 'QuirkyThing',
            'fqcn' => QuirkyThing::class,
            'columns' => [
                ['name' => 'id', 'type' => 'number', 'nullable' => false, 'is_server_filled' => true],
                ['name' => 'first-name', 'type' => 'string', 'nullable' => false, 'is_server_filled' => false],
            ],
            'relationships' => [],
            'assignable_columns' => [
                ['name' => 'first-name', 'type' => 'string', 'nullable' => false, 'is_server_filled' => false],
            ],
        ];

        $output = $this->typeScriptRenderer->renderModelFile($model, [], [], true, []);

        $this->assertStringNotContainsString('  first-name', $output);
        $this->assertStringContainsString("  'first-name': string;", $output);
        $this->assertStringContainsString("  'first-name'?: string;", $output);
    }

    public function test_pivot_columns_that_are_not_identifiers_are_quoted(): void
    {
        $output = $this->typeScriptRenderer->renderPivot([
            'name' => 'ThingPivot',
            'columns' => [['name' => 'sort-order', 'type' => 'number']],
            'withTimestamps' => false,
        ]);

        $this->assertStringContainsString("  'sort-order': number;", $output);
    }

    public function test_resource_shape_and_extend_keys_are_quoted(): void
    {
        $shape = $this->typeScriptRenderer->renderResource([
            'name' => 'ThingResource',
            'fqcn' => 'App\\Http\\Resources\\ThingResource',
            'shape' => ['kind' => 'shape', 'fields' => ['first-name' => 'string', 'x-y?' => 'number']],
        ], [], [], []);

        $this->assertStringContainsString("  'first-name': string;", $shape);
        $this->assertStringContainsString("  'x-y'?: number;", $shape);

        $extended = $this->typeScriptRenderer->renderResource([
            'name' => 'ThingResource',
            'fqcn' => 'App\\Http\\Resources\\ThingResource',
            'shape' => ['kind' => 'model', 'model' => QuirkyThing::class, 'omit' => ["it's"], 'extend' => ['avatar-url' => 'string']],
        ], [['name' => 'QuirkyThing', 'fqcn' => QuirkyThing::class]], [], []);

        $this->assertStringContainsString("Omit<QuirkyThing, 'it\\'s'> & { 'avatar-url': string }", $extended);
    }

    public function test_page_props_and_component_names_are_escaped(): void
    {
        $output = $this->typeScriptRenderer->renderPages([[
            'component' => "Audit/It's",
            'props' => ['can-edit' => 'boolean'],
            'optional' => [],
        ]], [], []);

        $this->assertStringContainsString("  'Audit/It\\'s': { 'can-edit': boolean };", $output);
    }

    public function test_broadcast_names_channels_and_payload_keys_are_escaped(): void
    {
        $output = $this->typeScriptRenderer->renderBroadcasting([[
            'event_class' => 'App\\Events\\Thing',
            'broadcast_name' => "user.it's",
            'channels' => [['type' => 'public', 'name' => "room.o'neil"]],
            'payload' => ['first-name' => 'string'],
        ]], [], []);

        $this->assertStringContainsString("'room.o\\'neil': { 'user.it\\'s': { 'first-name': string } };", $output);
        $this->assertStringContainsString("  'user.it\\'s': { 'first-name': string };", $output);
    }

    public function test_in_rule_values_are_escaped(): void
    {
        $output = $this->typeScriptRenderer->renderRequest([
            'name' => 'StoreThing',
            'fqcn' => 'App\\Http\\Requests\\StoreThing',
            'fields' => [['name' => 'surname', 'type' => ['in' => ["O'Brien", Post::class]], 'nullable' => false, 'required' => true]],
        ], []);

        $this->assertStringContainsString("surname: 'O\\'Brien' | 'App\\\\Models\\\\Post';", $output);
    }
}
