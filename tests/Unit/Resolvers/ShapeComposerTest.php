<?php

namespace Tests\Unit\Resolvers;

use Pentacore\Typefinder\Analysis\ReturnedArray;
use Pentacore\Typefinder\Analysis\ReturnedPart;
use Pentacore\Typefinder\Resolvers\ShapeComposer;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\Int_;
use PHPUnit\Framework\TestCase;

final class ShapeComposerTest extends TestCase
{
    /** @return array{kind: 'ts', ts: string} */
    private function ts(string $ts): array
    {
        return ['kind' => 'ts', 'ts' => $ts];
    }

    /** @return array{name: string, type: array, optional: bool} */
    private function field(string $name, array $type, bool $optional = false): array
    {
        return ['name' => $name, 'type' => $type, 'optional' => $optional];
    }

    /** @param list<array{name: string, type: array, optional: bool}> $fields */
    private function object(array $fields, bool $open = false): array
    {
        return ['kind' => 'object', 'fields' => $fields, 'open' => $open];
    }

    /** An `expr` part standing for the helper named $name. */
    private function expr(string $name, string $combine = 'spread', bool $conditional = false): ReturnedPart
    {
        return new ReturnedPart(ReturnedPart::EXPR, conditional: $conditional, expr: new Variable($name), combine: $combine);
    }

    private function item(string $key, bool $conditional = false): ReturnedPart
    {
        return new ReturnedPart(ReturnedPart::ITEM, key: $key, value: new Int_(1), conditional: $conditional);
    }

    /** @param list<ReturnedPart> $parts */
    private function returned(array $parts): ReturnedArray
    {
        return new ReturnedArray($parts);
    }

    /**
     * Compose with a fake resolver mapping each `expr` part's variable name
     * to an object node (missing name = unresolvable).
     *
     * @param  list<ReturnedArray>  $returns
     * @param  array<string, array>  $helpers
     */
    private function compose(array $returns, array $helpers): ?array
    {
        return new ShapeComposer()->compose(
            $returns,
            fn (ReturnedPart $part): ?array => $part->expr instanceof Variable && is_string($part->expr->name)
                ? ($helpers[$part->expr->name] ?? null)
                : null,
        );
    }

    /** @return array<string, array> */
    private function helpers(): array
    {
        return [
            'base' => $this->object([$this->field('id', $this->ts('number')), $this->field('name', $this->ts('string'))]),
            'counts' => $this->object([$this->field('posts_count', $this->ts('number'))]),
        ];
    }

    public function test_two_documented_helpers_spread_compose_in_order(): void
    {
        $result = $this->compose([$this->returned([$this->expr('base'), $this->expr('counts')])], $this->helpers());

        $this->assertSame([
            'type' => $this->object([
                $this->field('id', $this->ts('number')),
                $this->field('name', $this->ts('string')),
                $this->field('posts_count', $this->ts('number')),
            ]),
            'undocumented' => [],
        ], $result);
    }

    public function test_literal_item_is_unknown_and_listed_as_undocumented(): void
    {
        $result = $this->compose([$this->returned([$this->expr('base'), $this->item('extra')])], $this->helpers());

        $this->assertSame([
            'type' => $this->object([
                $this->field('id', $this->ts('number')),
                $this->field('name', $this->ts('string')),
                $this->field('extra', $this->ts('unknown')),
            ]),
            'undocumented' => ['extra'],
        ], $result);
    }

    public function test_a_later_required_key_overrides_an_earlier_one_in_place(): void
    {
        $helpers = $this->helpers() + [
            'override' => $this->object([$this->field('name', $this->ts('string | null'))]),
        ];

        $result = $this->compose([$this->returned([$this->expr('base'), $this->expr('override', 'merge')])], $helpers);

        $this->assertSame([
            'type' => $this->object([
                $this->field('id', $this->ts('number')),
                $this->field('name', $this->ts('string | null')),
            ]),
            'undocumented' => [],
        ], $result);
    }

    public function test_a_documented_helper_overriding_a_literal_documents_the_key(): void
    {
        $result = $this->compose([$this->returned([$this->item('id'), $this->expr('base')])], $this->helpers());

        $this->assertSame([
            'type' => $this->object([
                $this->field('id', $this->ts('number')),
                $this->field('name', $this->ts('string')),
            ]),
            'undocumented' => [],
        ], $result);
    }

    public function test_an_optional_override_unions_with_the_earlier_type_and_keeps_its_presence(): void
    {
        $helpers = $this->helpers() + [
            'maybe' => $this->object([$this->field('name', $this->ts('null'), optional: true)]),
        ];

        $result = $this->compose([$this->returned([$this->expr('base'), $this->expr('maybe')])], $helpers);

        $this->assertSame([
            'type' => $this->object([
                $this->field('id', $this->ts('number')),
                $this->field('name', ['kind' => 'union', 'of' => [$this->ts('string'), $this->ts('null')]]),
            ]),
            'undocumented' => [],
        ], $result);
    }

    public function test_an_optional_literal_over_a_documented_key_stays_undocumented(): void
    {
        $result = $this->compose([$this->returned([$this->expr('base'), $this->item('name', conditional: true)])], $this->helpers());

        $this->assertSame([
            'type' => $this->object([
                $this->field('id', $this->ts('number')),
                $this->field('name', ['kind' => 'union', 'of' => [$this->ts('string'), $this->ts('unknown')]]),
            ]),
            'undocumented' => ['name'],
        ], $result);
    }

    public function test_plus_without_a_key_collision_composes(): void
    {
        $result = $this->compose(
            [$this->returned([$this->expr('base', 'plus'), $this->expr('counts', 'plus'), $this->item('extra')])],
            $this->helpers(),
        );

        $this->assertSame([
            'type' => $this->object([
                $this->field('id', $this->ts('number')),
                $this->field('name', $this->ts('string')),
                $this->field('posts_count', $this->ts('number')),
                $this->field('extra', $this->ts('unknown')),
            ]),
            'undocumented' => ['extra'],
        ], $result);
    }

    public function test_plus_with_a_key_collision_is_not_composable(): void
    {
        $helpers = $this->helpers() + [
            'other' => $this->object([$this->field('id', $this->ts('string'))]),
        ];

        $this->assertNull($this->compose([$this->returned([$this->expr('base', 'plus'), $this->expr('other', 'plus')])], $helpers));
    }

    public function test_a_literal_colliding_with_a_helper_in_a_plus_return_is_not_composable(): void
    {
        // `array_merge($this->base() + $this->counts(), ['id' => …])`: the reader
        // flattens it, so the precedence of `id` cannot be told apart.
        $this->assertNull($this->compose(
            [$this->returned([$this->expr('base', 'plus'), $this->expr('counts', 'plus'), $this->item('id')])],
            $this->helpers(),
        ));
    }

    public function test_a_conditional_item_is_optional(): void
    {
        $result = $this->compose([$this->returned([$this->expr('counts'), $this->item('author', conditional: true)])], $this->helpers());

        $this->assertSame([
            'type' => $this->object([
                $this->field('posts_count', $this->ts('number')),
                $this->field('author', $this->ts('unknown'), optional: true),
            ]),
            'undocumented' => ['author'],
        ], $result);
    }

    public function test_a_conditional_expr_part_contributes_optional_keys(): void
    {
        $result = $this->compose(
            [$this->returned([$this->expr('counts'), $this->expr('base', 'merge', conditional: true)])],
            $this->helpers(),
        );

        $this->assertSame([
            'type' => $this->object([
                $this->field('posts_count', $this->ts('number')),
                $this->field('id', $this->ts('number'), optional: true),
                $this->field('name', $this->ts('string'), optional: true),
            ]),
            'undocumented' => [],
        ], $result);
    }

    public function test_keys_missing_from_some_returns_are_optional(): void
    {
        $result = $this->compose([
            $this->returned([$this->expr('base'), $this->item('a')]),
            $this->returned([$this->expr('base'), $this->item('b')]),
        ], $this->helpers());

        $this->assertSame([
            'type' => $this->object([
                $this->field('id', $this->ts('number')),
                $this->field('name', $this->ts('string')),
                $this->field('a', $this->ts('unknown'), optional: true),
                $this->field('b', $this->ts('unknown'), optional: true),
            ]),
            'undocumented' => ['a', 'b'],
        ], $result);
    }

    public function test_a_key_optional_in_one_return_is_optional(): void
    {
        $result = $this->compose([
            $this->returned([$this->expr('counts'), $this->item('a')]),
            $this->returned([$this->expr('counts'), $this->item('a', conditional: true)]),
        ], $this->helpers());

        $this->assertSame([
            'type' => $this->object([
                $this->field('posts_count', $this->ts('number')),
                $this->field('a', $this->ts('unknown'), optional: true),
            ]),
            'undocumented' => ['a'],
        ], $result);
    }

    public function test_types_are_unioned_across_returns(): void
    {
        $helpers = [
            'draft' => $this->object([$this->field('status', $this->ts("'draft'")), $this->field('id', $this->ts('number'))]),
            'published' => $this->object([$this->field('status', $this->ts("'published'")), $this->field('id', $this->ts('number'))]),
        ];

        $result = $this->compose([
            $this->returned([$this->expr('draft', 'whole')]),
            $this->returned([$this->expr('published', 'whole')]),
        ], $helpers);

        $this->assertSame([
            'type' => $this->object([
                $this->field('status', ['kind' => 'union', 'of' => [$this->ts("'draft'"), $this->ts("'published'")]]),
                $this->field('id', $this->ts('number')),
            ]),
            'undocumented' => [],
        ], $result);
    }

    public function test_an_empty_return_makes_every_key_optional(): void
    {
        $result = $this->compose([
            $this->returned([]),
            $this->returned([$this->expr('base'), $this->item('extra')]),
        ], $this->helpers());

        $this->assertSame([
            'type' => $this->object([
                $this->field('id', $this->ts('number'), optional: true),
                $this->field('name', $this->ts('string'), optional: true),
                $this->field('extra', $this->ts('unknown'), optional: true),
            ]),
            'undocumented' => ['extra'],
        ], $result);
    }

    public function test_a_dynamic_part_is_not_composable(): void
    {
        $this->assertNull($this->compose([
            $this->returned([$this->expr('base'), new ReturnedPart(ReturnedPart::DYNAMIC, value: new Int_(1))]),
        ], $this->helpers()));
    }

    public function test_an_unresolvable_expr_is_not_composable(): void
    {
        $this->assertNull($this->compose([$this->returned([$this->expr('base'), $this->expr('unknown')])], $this->helpers()));
    }

    public function test_an_unresolvable_expr_in_any_return_is_not_composable(): void
    {
        $this->assertNull($this->compose([
            $this->returned([$this->expr('base')]),
            $this->returned([$this->expr('unknown', 'whole')]),
        ], $this->helpers()));
    }

    public function test_a_non_object_resolution_is_not_composable(): void
    {
        $this->assertNull($this->compose([$this->returned([$this->expr('base'), $this->expr('list')])], $this->helpers() + [
            'list' => ['kind' => 'array', 'of' => $this->ts('number')],
        ]));
    }

    public function test_literal_only_returns_are_not_composable(): void
    {
        $this->assertNull($this->compose([
            $this->returned([$this->item('id'), $this->item('name')]),
            $this->returned([]),
        ], $this->helpers()));
    }

    public function test_no_returns_are_not_composable(): void
    {
        $this->assertNull($this->compose([], $this->helpers()));
    }

    public function test_an_open_helper_shape_makes_the_result_open(): void
    {
        $helpers = $this->helpers() + [
            'loose' => $this->object([$this->field('meta', $this->ts('unknown'))], open: true),
        ];

        $result = $this->compose([
            $this->returned([$this->expr('base')]),
            $this->returned([$this->expr('loose')]),
        ], $helpers);

        $this->assertSame([
            'type' => $this->object([
                $this->field('id', $this->ts('number'), optional: true),
                $this->field('name', $this->ts('string'), optional: true),
                $this->field('meta', $this->ts('unknown'), optional: true),
            ], open: true),
            'undocumented' => [],
        ], $result);
    }
}
