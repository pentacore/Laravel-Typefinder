<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Http\Requests\StoreAnyOfArrayRequest;
use App\Http\Requests\StoreArrayRulesRequest;
use Illuminate\Validation\Rules\AnyOf;
use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #74: `array` / `list` rules leaked the internal `array` marker into
 * the emitted TypeScript, and structured wildcard item types rendered as
 * `Array[]`.
 */
final class ArrayRuleTypesTest extends TestCase
{
    private const string ARRAY_OR_RECORD = 'unknown[] | Record<string, unknown>';

    private RequestExtractor $requestExtractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requestExtractor = new RequestExtractor;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bareArrayFields(): iterable
    {
        yield 'sometimes|nullable|array' => ['options', self::ARRAY_OR_RECORD];
        yield 'required|array' => ['payload', self::ARRAY_OR_RECORD];
        yield 'list' => ['ids', 'unknown[]'];
        yield 'array:name,email' => ['contact', '{ name?: unknown; email?: unknown }'];
        yield 'array keys that are not identifiers' => ['headers', "{ 'content-type'?: unknown; accept?: unknown }"];
        yield 'Rule::array()' => ['filters', self::ARRAY_OR_RECORD];
        yield 'Rule::array([keys]), with ->min() on Laravel 13' => ['address', '{ street?: unknown; city?: unknown }'];
        yield 'list with wildcard after other rules' => ['counts', 'number[]'];
        yield 'Rule::array() with wildcard' => ['labels', 'string[]'];
        yield 'wildcard item is an array' => ['matrix', '('.self::ARRAY_OR_RECORD.')[]'];
        yield 'wildcard item is a list' => ['lists', 'unknown[][]'];
        yield 'confirmation copy' => ['secret_confirmation', self::ARRAY_OR_RECORD];
    }

    #[DataProvider('bareArrayFields')]
    public function test_array_rules_resolve_to_typescript(string $name, string $expected): void
    {
        $field = $this->findField($this->extract(), $name);

        $this->assertNotNull($field, sprintf('field %s missing', $name));
        $this->assertSame($expected, $field['type']);
    }

    public function test_nullable_array_keeps_flags(): void
    {
        $field = $this->findField($this->extract(), 'options');

        $this->assertFalse($field['required']);
        $this->assertTrue($field['nullable']);
    }

    public function test_keyed_array_rule_after_required_still_folds_nested_children(): void
    {
        $field = $this->findField($this->extract(), 'profile');

        $this->assertSame('object', $field['type']);
        $this->assertTrue($field['required']);
        $this->assertSame('string', $this->findChild($field, 'name')['type']);
        $this->assertSame('number', $this->findChild($field, 'age')['type']);
    }

    public function test_nested_array_child_resolves(): void
    {
        $field = $this->findField($this->extract(), 'meta');

        $this->assertSame('object', $field['type']);
        $this->assertSame(self::ARRAY_OR_RECORD, $this->findChild($field, 'extra')['type']);
    }

    public function test_object_array_child_resolves(): void
    {
        $field = $this->findField($this->extract(), 'rows');

        $this->assertSame('object-array', $field['type']);
        $this->assertSame(self::ARRAY_OR_RECORD, $this->findChild($field, 'cells')['type']);
    }

    public function test_structured_wildcard_items_are_wrapped_in_array_of(): void
    {
        $result = $this->extract();

        $this->assertSame(['arrayOf' => ['in' => ['s', 'm', 'l']]], $this->findField($result, 'sizes')['type']);
        $this->assertSame(['arrayOf' => ['in' => ['a', 'b']]], $this->findField($result, 'grades')['type']);
        $this->assertSame(['arrayOf' => ['enum' => PostStatus::class]], $this->findField($result, 'statuses')['type']);
    }

    public function test_any_of_with_array_branch(): void
    {
        if (! class_exists(AnyOf::class)) {
            $this->markTestSkipped('Rule::anyOf() requires Laravel 13+.');
        }

        $result = $this->requestExtractor->extract(StoreAnyOfArrayRequest::class);

        $this->assertSame(['anyOf' => [self::ARRAY_OR_RECORD, 'string']], $this->findField($result, 'value')['type']);
        $this->assertSame(['anyOf' => ['unknown[]', 'number']], $this->findField($result, 'choice')['type']);
    }

    public function test_renders_inline_nested_shapes(): void
    {
        $output = $this->render(false);

        $this->assertStringContainsString('  options?: '.self::ARRAY_OR_RECORD." | null;\n", $output);
        $this->assertStringContainsString('  payload: '.self::ARRAY_OR_RECORD.";\n", $output);
        $this->assertStringContainsString("  ids: unknown[];\n", $output);
        $this->assertStringContainsString("  contact?: { name?: unknown; email?: unknown };\n", $output);
        $this->assertStringContainsString("  sizes?: ('s' | 'm' | 'l')[];\n", $output);
        $this->assertStringContainsString("  grades?: ('a' | 'b')[];\n", $output);
        $this->assertStringContainsString("  statuses?: PostStatus[];\n", $output);
        $this->assertStringContainsString("import type { PostStatus } from '../enums';", $output);
        $this->assertStringContainsString('  matrix?: ('.self::ARRAY_OR_RECORD.")[];\n", $output);
        $this->assertStringContainsString('    extra?: '.self::ARRAY_OR_RECORD.";\n", $output);
        $this->assertStringContainsString('    cells: '.self::ARRAY_OR_RECORD.";\n  }[];\n", $output);
        $this->assertStringContainsString('  secret_confirmation?: '.self::ARRAY_OR_RECORD.";\n", $output);
        $this->assertNoArrayMarker($output);
    }

    public function test_renders_extracted_nested_shapes(): void
    {
        $output = $this->render(true);

        $this->assertStringContainsString(
            "export type StoreArrayRulesRequestMeta = {\n  title?: string;\n  extra?: ".self::ARRAY_OR_RECORD.";\n};",
            $output,
        );
        $this->assertStringContainsString(
            "export type StoreArrayRulesRequestRows = {\n  cells: ".self::ARRAY_OR_RECORD.";\n};",
            $output,
        );
        $this->assertStringContainsString("  rows?: StoreArrayRulesRequestRows[];\n", $output);
        $this->assertStringContainsString("  sizes?: ('s' | 'm' | 'l')[];\n", $output);
        $this->assertNoArrayMarker($output);
    }

    public function test_renderer_wraps_array_of_items(): void
    {
        $typeScriptRenderer = new TypeScriptRenderer;
        $request = [
            'name' => 'Req',
            'fields' => [
                ['name' => 'a', 'type' => ['arrayOf' => 'string'], 'required' => true, 'nullable' => false],
                ['name' => 'b', 'type' => ['arrayOf' => ['in' => ['x', 'y']]], 'required' => true, 'nullable' => true],
                ['name' => 'c', 'type' => ['arrayOf' => ['arrayOf' => ['in' => ['x']]]], 'required' => true, 'nullable' => false],
            ],
        ];

        $output = $typeScriptRenderer->renderRequest($request, []);

        $this->assertStringContainsString("  a: string[];\n", $output);
        $this->assertStringContainsString("  b: ('x' | 'y')[] | null;\n", $output);
        $this->assertStringContainsString("  c: 'x'[][];\n", $output);
    }

    private function render(bool $extractNested): string
    {
        $enums = [['name' => 'PostStatus', 'fqcn' => PostStatus::class]];

        return new TypeScriptRenderer()->renderRequest($this->extract(), $enums, $extractNested);
    }

    private function assertNoArrayMarker(string $output): void
    {
        $this->assertDoesNotMatchRegularExpression('/:\s*array\b/', $output);
        $this->assertDoesNotMatchRegularExpression('/\barray\[\]/', $output);
        $this->assertDoesNotMatchRegularExpression('/\bArray\b/', $output);
    }

    private function extract(): array
    {
        return $this->requestExtractor->extract(StoreArrayRulesRequest::class);
    }

    private function findField(array $result, string $name): ?array
    {
        return collect($result['fields'])->firstWhere('name', $name);
    }

    private function findChild(array $field, string $name): ?array
    {
        return collect($field['children'])->firstWhere('name', $name);
    }
}
