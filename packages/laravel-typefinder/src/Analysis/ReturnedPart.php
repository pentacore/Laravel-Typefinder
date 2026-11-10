<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Analysis;

use PhpParser\Node\Expr;

/**
 * One piece of an array a method returns, in source order.
 *
 * - `item`: an entry with a string-literal key; `$value` is its value node.
 * - `dynamic`: an entry with a non-literal key, or no key; `$value` is set.
 * - `expr`: an opaque sub-expression combined into the array (`$expr`), with
 *   `$combine` saying how: `spread` (`...$x`), `merge` (an `array_merge()`
 *   argument or a `merge()`/`mergeWhen()` argument that is not a literal
 *   array), `plus` (an operand of `+`) or `whole` (the entire expression).
 *   `$expr` is null when the unknown keys come from outside any expression
 *   (props an action may pass on another code path).
 *
 * `$conditional` is set on items whose presence depends on runtime state
 * (`when*()` without a default, `mergeWhen()` items, configured wrappers such
 * as `Inertia::optional()`, `JsonResource::collection()` of such a value),
 * and on every `expr` part produced by a `mergeWhen()` (a non-literal value or
 * default branch).
 */
final readonly class ReturnedPart
{
    public const string ITEM = 'item';

    public const string DYNAMIC = 'dynamic';

    public const string EXPR = 'expr';

    public function __construct(
        public string $kind,
        public ?string $key = null,
        public ?Expr $value = null,
        public bool $conditional = false,
        public ?Expr $expr = null,
        public string $combine = 'whole',
    ) {}
}
