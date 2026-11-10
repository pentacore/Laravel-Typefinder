<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Resolvers;

use Pentacore\Typefinder\Analysis\ReturnedArray;
use Pentacore\Typefinder\Analysis\ReturnedPart;

/**
 * Composes an object type node (the {@see PhpDocTypeMapper} type-tree format)
 * from the arrays a method returns, when they are built from parts whose
 * shapes are known: delegated helpers (`expr` parts, typed by the caller's
 * resolver) and literal-key items (typed `unknown`).
 *
 * Within one return, parts merge in source order and a later key overrides
 * an earlier one; an optional later key cannot hide the earlier value, so the
 * two types are unioned and the key keeps the earlier presence. `+` keeps the
 * earlier key instead, but the reader flattens nesting (`array_merge($a + $b,
 * $c)`), so a return that uses `+` and holds a key twice is not composable.
 *
 * Across returns, a key required in every return is required, otherwise
 * optional; its types are unioned. The result is open when any helper shape
 * is open.
 *
 * Not composable (null): no returns, a `dynamic` part, an `expr` part the
 * resolver cannot type, or no `expr` part at all (a literal-only body is not
 * delegation).
 */
final class ShapeComposer
{
    private const array UNKNOWN = ['kind' => 'ts', 'ts' => 'unknown'];

    /**
     * @param  list<ReturnedArray>  $returns
     * @param  callable(ReturnedPart): ?array  $resolveExpr  object node for an 'expr' part, or null = not composable
     * @return ?array{type: array, undocumented: list<string>} object node + literal keys with no documented type; null = not composable
     */
    public function compose(array $returns, callable $resolveExpr): ?array
    {
        $composed = [];
        $delegated = false;

        foreach ($returns as $returned) {
            $one = $this->composeReturn($returned, $resolveExpr);

            if ($one === null) {
                return null;
            }

            $composed[] = $one;
            $delegated = $delegated || $one['delegated'];
        }

        return $delegated ? $this->acrossReturns($composed) : null;
    }

    /**
     * @param  callable(ReturnedPart): ?array  $resolveExpr
     * @return ?array{fields: array<string, array{type: array, optional: bool, literal: bool}>, open: bool, delegated: bool}
     */
    private function composeReturn(ReturnedArray $returned, callable $resolveExpr): ?array
    {
        $fields = [];
        $open = false;
        $delegated = false;
        $plus = false;
        $collision = false;

        foreach ($returned->parts as $part) {
            $plus = $plus || $part->combine === 'plus';
            $contributions = $this->contributions($part, $resolveExpr);

            if ($contributions === null) {
                return null;
            }

            if ($part->kind === ReturnedPart::EXPR) {
                $delegated = true;
                $open = $open || $contributions['open'];
            }

            foreach ($contributions['fields'] as $name => $field) {
                $collision = $collision || array_key_exists($name, $fields);
                $fields[$name] = $this->override($fields[$name] ?? null, $field);
            }
        }

        if ($plus && $collision) {
            return null;
        }

        return ['fields' => $fields, 'open' => $open, 'delegated' => $delegated];
    }

    /**
     * The fields one part adds, keyed by name.
     *
     * @param  callable(ReturnedPart): ?array  $resolveExpr
     * @return ?array{fields: array<string, array{type: array, optional: bool, literal: bool}>, open: bool}
     */
    private function contributions(ReturnedPart $part, callable $resolveExpr): ?array
    {
        if ($part->kind === ReturnedPart::ITEM && $part->key !== null) {
            return [
                'fields' => [$part->key => ['type' => self::UNKNOWN, 'optional' => $part->conditional, 'literal' => true]],
                'open' => false,
            ];
        }

        if ($part->kind !== ReturnedPart::EXPR) {
            return null;
        }

        $node = $resolveExpr($part);

        if (! is_array($node) || ($node['kind'] ?? null) !== 'object') {
            return null;
        }

        $fields = [];

        foreach ($node['fields'] as $field) {
            $fields[(string) $field['name']] = [
                'type' => $field['type'],
                'optional' => $field['optional'] || $part->conditional,
                'literal' => false,
            ];
        }

        return ['fields' => $fields, 'open' => (bool) $node['open']];
    }

    /**
     * A later key over an earlier one: a required key replaces it; an
     * optional key may be absent, so the earlier value may survive.
     *
     * @param  ?array{type: array, optional: bool, literal: bool}  $earlier
     * @param  array{type: array, optional: bool, literal: bool}  $later
     * @return array{type: array, optional: bool, literal: bool}
     */
    private function override(?array $earlier, array $later): array
    {
        if ($earlier === null || ! $later['optional']) {
            return $later;
        }

        return [
            'type' => $this->union([$earlier['type'], $later['type']]),
            'optional' => $earlier['optional'],
            'literal' => $earlier['literal'] || $later['literal'],
        ];
    }

    /**
     * @param  non-empty-list<array{fields: array<string, array{type: array, optional: bool, literal: bool}>, open: bool, delegated: bool}>  $composed
     * @return array{type: array, undocumented: list<string>}
     */
    private function acrossReturns(array $composed): array
    {
        /** @var array<string, array{types: list<array>, optional: bool, literal: bool}> $merged */
        $merged = [];
        $open = false;

        foreach ($composed as $one) {
            $open = $open || $one['open'];

            foreach ($one['fields'] as $name => $field) {
                $merged[$name] ??= ['types' => [], 'optional' => false, 'literal' => false];
                $merged[$name]['types'][] = $field['type'];
                $merged[$name]['optional'] = $merged[$name]['optional'] || $field['optional'];
                $merged[$name]['literal'] = $merged[$name]['literal'] || $field['literal'];
            }
        }

        $fields = [];
        $undocumented = [];

        foreach ($merged as $name => $field) {
            $name = (string) $name;
            $present = array_all($composed, fn (array $one): bool => array_key_exists($name, $one['fields']));

            $fields[] = [
                'name' => $name,
                'type' => $this->union($field['types']),
                'optional' => $field['optional'] || ! $present,
            ];

            if ($field['literal']) {
                $undocumented[] = $name;
            }
        }

        return [
            'type' => ['kind' => 'object', 'fields' => $fields, 'open' => $open],
            'undocumented' => $undocumented,
        ];
    }

    /**
     * Flattened, deduplicated union; a single member is returned as is.
     *
     * @param  non-empty-list<array>  $types
     */
    private function union(array $types): array
    {
        $members = [];

        foreach ($types as $type) {
            foreach (($type['kind'] ?? null) === 'union' ? $type['of'] : [$type] as $member) {
                if (! in_array($member, $members, true)) {
                    $members[] = $member;
                }
            }
        }

        return count($members) === 1 ? $members[0] : ['kind' => 'union', 'of' => $members];
    }
}
