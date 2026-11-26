<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Support;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceResponse;

/**
 * The key a JSON resource's response wraps its data in, as
 * {@see ResourceResponse::wrap()} decides it (#241).
 *
 * Read when the types are generated, inside the booted app, so a
 * `JsonResource::withoutWrapping()` or `wrap()` call in a service provider
 * counts, as does a resource's own `public static $wrap`.
 */
final class ResourceWrapper
{
    /**
     * The wrapper key, or null when the response is the bare resource: no
     * wrapper is set, or (unless wrapping is forced) the resource's shape
     * always has that key itself.
     *
     * @param  class-string<JsonResource>  $fqcn
     * @param  ?array{shape?: array<string, mixed>}  $resource  The resource's extraction; null when not known.
     * @param  list<array<string, mixed>>  $allModels
     */
    public static function key(string $fqcn, ?array $resource, array $allModels): ?string
    {
        $wrap = $fqcn::$wrap;

        if (! is_string($wrap) || $wrap === '') {
            return null;
        }

        if (property_exists($fqcn, 'forceWrapping') && $fqcn::$forceWrapping) {
            return $wrap;
        }

        return self::alwaysHasKey($resource['shape'] ?? null, $wrap, $allModels) ? null : $wrap;
    }

    /**
     * Whether the declared shape always has `$key` at its top level. An
     * optional key may be missing from the response, which is then wrapped,
     * so it does not count.
     *
     * @param  ?array<string, mixed>  $shape
     * @param  list<array<string, mixed>>  $allModels
     */
    private static function alwaysHasKey(?array $shape, string $key, array $allModels): bool
    {
        return match ($shape['kind'] ?? null) {
            'shape' => array_key_exists($key, (array) $shape['fields']),
            'phpdoc', 'inferred' => ($shape['type']['kind'] ?? null) === 'object'
                && array_any((array) $shape['type']['fields'], fn (array $field): bool => $field['name'] === $key && ! $field['optional']),
            'model' => self::modelAlwaysHasKey($shape, $key, $allModels),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $shape
     * @param  list<array<string, mixed>>  $allModels
     */
    private static function modelAlwaysHasKey(array $shape, string $key, array $allModels): bool
    {
        $extend = (array) ($shape['extend'] ?? []);

        if (array_key_exists($key, $extend)) {
            return true;
        }

        if (in_array($key, array_map(strval(...), (array) ($shape['omit'] ?? [])), true)) {
            return false;
        }

        $model = array_find($allModels, fn (array $model): bool => ($model['fqcn'] ?? null) === $shape['model']);

        // Relations are only there when loaded, so only columns count.
        return array_any(
            (array) ($model['columns'] ?? []),
            fn (array $column): bool => $column['name'] === $key && empty($column['optional']),
        );
    }
}
