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
 * Precision over recall: whenever the merged result is uncertain the
 * composition fails (null) and the caller falls through as before.
 *
 * Within one return, parts merge in source order and a later required key
 * overrides an earlier one (spread, `array_merge()`, literal order). Not
 * composable instead when:
 *  - a later part contributes an earlier key as optional (a conditional item,
 *    a `k?:` field, any key of a conditional or `mergeWhen()` branch part):
 *    a `MissingValue` removes the key, it does not bring the earlier value
 *    back;
 *  - a key comes from more than one part of a return that uses `+` or a
 *    `merge()`/`mergeWhen()`/`mergeUnless()` call: those keep the earliest
 *    key, and the reader flattens nesting, so the winner cannot be told;
 *  - an open helper shape (it may hold any key) sits beside other keys
 *    anywhere but first, or in a return with `+` or a merge call;
 *  - a return uses both `+` and a merge call: two merges on both sides of
 *    `+` share key 0, so one is dropped;
 *  - a merged helper whose shape has no required key is followed by a part
 *    that adds keys: an empty merge is spliced as a list and every key
 *    after it is renumbered.
 *
 * Every key of a `mergeWhen()`/`mergeUnless()` branch part is optional.
 *
 * Across returns, a key required in every return is required, otherwise
 * optional; its types are unioned. The result is open when any helper shape
 * is open.
 *
 * Also not composable: no returns, a `dynamic` part, an `expr` part the
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
        $plus = $returned->usesPlus();
        $merges = array_any($returned->parts, fn (ReturnedPart $part): bool => $part->merged !== null);

        if ($plus && $merges) {
            return null;
        }

        $fields = [];
        $delegated = false;
        $collision = false;
        $earliestWins = $plus || $merges;
        $mayBeEmptyMerge = false;
        $openAt = null;
        $contributing = 0;

        foreach ($returned->parts as $index => $part) {
            $contributions = $this->contributions($part, $resolveExpr);

            if ($contributions === null) {
                return null;
            }

            $contributes = $contributions['open'] || $contributions['fields'] !== [];

            if ($mayBeEmptyMerge && $contributes) {
                return null;
            }

            if ($part->kind === ReturnedPart::EXPR) {
                $delegated = true;
                $mayBeEmptyMerge = $mayBeEmptyMerge || ($part->merged !== null && ! $contributions['required']);
            }

            if ($contributions['open']) {
                if ($openAt !== null) {
                    return null;
                }

                $openAt = $index;
            }

            if ($contributes) {
                $contributing++;
            }

            foreach ($contributions['fields'] as $name => $field) {
                if (array_key_exists($name, $fields)) {
                    if ($field['optional']) {
                        return null;
                    }

                    $collision = true;
                }

                $fields[$name] = $field;
            }
        }

        if ($earliestWins && $collision) {
            return null;
        }

        if ($openAt !== null && $contributing > 1 && ($openAt !== 0 || $earliestWins)) {
            return null;
        }

        return ['fields' => $fields, 'open' => $openAt !== null, 'delegated' => $delegated];
    }

    /**
     * The fields one part adds, keyed by name.
     *
     * `required`: the resolved shape itself has a required key, so the
     * array it stands for is never empty (always true for an item).
     *
     * @param  callable(ReturnedPart): ?array  $resolveExpr
     * @return ?array{fields: array<string, array{type: array, optional: bool, literal: bool}>, open: bool, required: bool}
     */
    private function contributions(ReturnedPart $part, callable $resolveExpr): ?array
    {
        if ($part->kind === ReturnedPart::ITEM && $part->key !== null) {
            return [
                'fields' => [$part->key => ['type' => self::UNKNOWN, 'optional' => $this->isOptional($part), 'literal' => true]],
                'open' => false,
                'required' => true,
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
        $required = false;

        foreach ($node['fields'] as $field) {
            $required = $required || ! $field['optional'];
            $fields[(string) $field['name']] = [
                'type' => $field['type'],
                'optional' => $field['optional'] || $this->isOptional($part),
                'literal' => false,
            ];
        }

        return ['fields' => $fields, 'open' => (bool) $node['open'], 'required' => $required];
    }

    /**
     * A part whose keys may be absent: a conditional part, or a branch of a
     * `mergeWhen()`/`mergeUnless()` (the reader does not mark an item
     * conditional when the other branch is opaque).
     */
    private function isOptional(ReturnedPart $part): bool
    {
        return $part->conditional || $part->merged === ReturnedPart::MERGED_BRANCH;
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
