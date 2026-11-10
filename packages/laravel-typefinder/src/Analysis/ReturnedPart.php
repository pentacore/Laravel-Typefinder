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
 *   argument, or a `merge()`/`mergeWhen()`/`mergeUnless()` argument that is
 *   not a literal array), `plus` (an operand of `+`) or `whole` (the entire
 *   expression). `$expr` is null when the unknown keys come from outside any
 *   expression (props an action may pass on another code path).
 *
 * `$conditional` is set on items whose presence depends on runtime state
 * (`when*()`/`unless()`/`transform()` without a default, `mergeWhen()` and
 * `mergeUnless()` items, configured wrappers such as `Inertia::optional()`,
 * `JsonResource::collection()` of such a value), and on every `expr` part
 * produced by a `mergeWhen()`/`mergeUnless()` (a non-literal value or default
 * branch).
 *
 * `$merged` records that the part came out of a resource merge call, nested
 * ones included: {@see self::MERGED_ALWAYS} for `merge()`,
 * {@see self::MERGED_BRANCH} for a `mergeWhen()`/`mergeUnless()` branch (a
 * branch inside a `merge()` and vice versa is a branch). Laravel splices a
 * merge with `+`, so the earliest key wins there. Null otherwise. It does not
 * change {@see ReturnedArray::keys()} or {@see ReturnedArray::isOpen()}.
 */
final readonly class ReturnedPart
{
    public const string ITEM = 'item';

    public const string DYNAMIC = 'dynamic';

    public const string EXPR = 'expr';

    /** `$merged`: the part came out of a `merge()` call. */
    public const string MERGED_ALWAYS = 'always';

    /** `$merged`: the part came out of a `mergeWhen()`/`mergeUnless()` branch. */
    public const string MERGED_BRANCH = 'branch';

    public function __construct(
        public string $kind,
        public ?string $key = null,
        public ?Expr $value = null,
        public bool $conditional = false,
        public ?Expr $expr = null,
        public string $combine = 'whole',
        public ?string $merged = null,
    ) {}
}
