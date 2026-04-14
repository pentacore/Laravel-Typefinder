<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Attributes;

use Attribute;

/**
 * Map a controller action to an Inertia page component, so Typefinder can
 * generate a typed `PageProps` entry for it.
 *
 * Applied per action method. Each attribute produces one entry keyed by
 * `$component` in the generated `pages.d.ts`. The attribute is repeatable —
 * an action that renders the same component with different prop sets can
 * carry multiple `#[TypefinderPage]` attributes, though the more common
 * pattern is one per method.
 *
 * Prop values may be literal TS type strings or class-strings. Model /
 * enum / resource class-strings resolve to their generated short name and
 * trigger the matching import. Anything unrecognised is emitted verbatim.
 *
 * Example:
 * ```php
 * use Pentacore\Typefinder\Attributes\TypefinderPage;
 *
 * class UserController
 * {
 *     #[TypefinderPage(
 *         component: 'Users/Show',
 *         props: [
 *             'user' => \App\Models\User::class,
 *             'canEdit' => 'boolean',
 *             'invitations' => 'string[]',
 *         ],
 *         optional: ['invitations'],
 *     )]
 *     public function show(\App\Models\User $user) { … }
 * }
 * ```
 *
 * Generation is opt-in via `config('typefinder.inertia.enabled')`. Components
 * declared by two different methods cause the generator to fail loudly so the
 * ambiguity is caught at source.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class TypefinderPage
{
    /**
     * @param  string  $component  Inertia component name, e.g. `'Users/Show'`.
     * @param  array<string, string>  $props  Prop name → TS type string or class-string.
     * @param  list<string>  $optional  Prop names emitted as `name?: T` (non-required).
     */
    public function __construct(
        public string $component,
        public array $props = [],
        public array $optional = [],
    ) {}
}
