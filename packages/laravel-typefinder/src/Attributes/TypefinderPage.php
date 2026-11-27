<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Attributes;

use Attribute;

/**
 * Map a controller action to an Inertia page component, so Typefinder can
 * generate a typed `PageProps` entry for it.
 *
 * Applied per action method. Each component gets one entry in the generated
 * `pages.d.ts`. Several actions (or several attributes on one action, since
 * the attribute is repeatable) may name the same component; their props
 * merge. A prop declared by every one of them, and optional in none, is
 * required; any other prop becomes optional.
 *
 * Prop values may be literal TS type strings, class-strings or PHPDoc
 * types (`array{id: int}`, `list<User>`, `?string`). Model / enum /
 * resource class-strings resolve to their generated short name and trigger
 * the matching import. A PHPDoc type is converted to TypeScript, its short
 * class names resolved through the controller's imports. A type that mixes
 * PHPDoc and TypeScript, or is no valid type, becomes `unknown` with a
 * warning. Any other TypeScript is emitted verbatim.
 *
 * Example:
 * <pre>
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
 * </pre>
 *
 * Generation is opt-in via `config('typefinder.inertia.enabled')`. A prop
 * that two declarations of one component give different types fails the
 * run, naming each action and its type.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class TypefinderPage
{
    /**
     * @param  string  $component  Inertia component name, e.g. `'Users/Show'`.
     * @param  array<string, string>  $props  Prop name → TS type string, class-string or PHPDoc type.
     * @param  list<string>  $optional  Prop names emitted as `name?: T` (non-required).
     */
    public function __construct(
        public string $component,
        public array $props = [],
        public array $optional = [],
    ) {}
}
