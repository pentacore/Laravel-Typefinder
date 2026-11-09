<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Attributes;

use Attribute;

/**
 * Declare the TypeScript shape of a `JsonResource` subclass.
 *
 * Typefinder can't statically walk `toArray()` method bodies (they're arbitrary
 * PHP code), so resource shapes are declared. The first match wins:
 *
 *   1. `shape` — explicit key-by-key declaration (Tier 1).
 *   2. `model` + `omit` / `extend` — "wrap this model with tweaks" (Tier 2).
 *   3. A PHPDoc `@return array{…}` (or `@phpstan-return` / `@psalm-return`)
 *      on `toArray()`, with no attribute present. Applies even when the name
 *      convention is off; never to abstract resources.
 *   4. Class-name convention — if the resource is named `{Model}Resource`,
 *      no attribute or PHPDoc shape is present and `{Model}` is one of the
 *      generated models, the generator emits `export type UserResource = User;`
 *      automatically. Disable with `typefinder.resources.infer_from_model_name`.
 *
 * A resource matching none of these is skipped with a warning.
 *
 * Tier 1 example:
 * <pre>
 * use Pentacore\Typefinder\Attributes\TypefinderResource;
 *
 * #[TypefinderResource(shape: [
 *     'id' => 'number',
 *     'title' => 'string',
 *     'author' => \App\Http\Resources\UserResource::class,
 *     'published_at' => 'string | null',
 * ])]
 * class PostResource extends \Illuminate\Http\Resources\Json\JsonResource {}
 * </pre>
 *
 * Tier 2 example — the common "resource wraps a model":
 * <pre>
 * #[TypefinderResource(
 *     model: \App\Models\User::class,
 *     omit: ['password', 'remember_token'],
 *     extend: ['avatarUrl' => 'string', 'postsCount' => 'number'],
 * )]
 * class AdminUserResource extends \Illuminate\Http\Resources\Json\JsonResource {}
 * </pre>
 *
 * Emits `export type AdminUserResource = Omit<User, 'password' | 'remember_token'> & { avatarUrl: string; postsCount: number };`.
 *
 * `$shape` and `$model` are mutually exclusive — setting both throws at
 * generation time. When `$shape` is set, `$omit` and `$extend` are ignored.
 *
 * Generation is default-on via `config('typefinder.resources.enabled')`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TypefinderResource
{
    /**
     * @param  array<string, string>  $shape  Explicit field map. Mutually exclusive with `$model`.
     * @param  ?string  $model  Model FQCN to wrap in Tier 2.
     * @param  list<string>  $omit  Model field names to exclude (Tier 2 only).
     * @param  array<string, string>  $extend  Extra fields appended to the model (Tier 2 only).
     */
    public function __construct(
        public array $shape = [],
        public ?string $model = null,
        public array $omit = [],
        public array $extend = [],
    ) {}
}
