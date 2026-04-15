<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Renderers\Concerns;

use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;

/**
 * Resource type rendering across the three declaration tiers: explicit shape,
 * model extension (`Omit<…> & { … }`), and name-convention passthrough.
 *
 * Also provides `resolveAnyTypeString` — used by resources, pages, and
 * broadcasts — to resolve class-strings (models / enums / JsonResources)
 * to their generated short names with matching imports.
 *
 * Mixed into {@see TypeScriptRenderer};
 * relies on the host class's `resolveModelName`, `resolvePagePropType`,
 * and `FILE_HEADER`.
 */
trait RendersResources
{
    /**
     * Render a JSON resource (.d.ts) file content.
     *
     * @param  array{name: string, fqcn: string, shape: array}  $resource
     * @param  list<array>  $allModels
     * @param  list<array>  $allEnums
     * @param  list<array>  $allResources
     */
    public function renderResource(array $resource, array $allModels, array $allEnums, array $allResources): string
    {
        $imports = [];
        $name = $resource['name'];
        $shape = $resource['shape'];

        if ($shape['kind'] === 'shape') {
            $lines = [];
            foreach ($shape['fields'] as $fieldName => $type) {
                $resolved = $this->resolveAnyTypeString((string) $type, $allModels, $allEnums, $allResources, $imports, $resource['fqcn']);
                $lines[] = sprintf('  %s: %s;', $fieldName, $resolved);
            }

            return $this->assembleResourceFile($imports, "export type {$name} = {\n".implode("\n", $lines)."\n};\n");
        }

        $modelShort = $this->resolveModelName($shape['model'], $allModels);
        $imports[] = sprintf("import type { %s } from '../models';", $modelShort);

        $body = sprintf('export type %s = ', $name);

        $omit = $shape['omit'];
        $extend = $shape['extend'];

        if ($omit !== []) {
            $omitUnion = implode(' | ', array_map(fn (string $field): string => sprintf("'%s'", $field), $omit));
            $body .= sprintf('Omit<%s, %s>', $modelShort, $omitUnion);
        } else {
            $body .= $modelShort;
        }

        if ($extend !== []) {
            $parts = [];
            foreach ($extend as $fieldName => $type) {
                $resolved = $this->resolveAnyTypeString((string) $type, $allModels, $allEnums, $allResources, $imports, $resource['fqcn']);
                $parts[] = sprintf('%s: %s', $fieldName, $resolved);
            }

            $body .= ' & { '.implode('; ', $parts).' }';
        }

        $body .= ";\n";

        return $this->assembleResourceFile($imports, $body);
    }

    /**
     * @param  list<string>  $imports
     */
    protected function assembleResourceFile(array $imports, string $body): string
    {
        $imports = array_values(array_unique($imports));
        sort($imports);

        $output = self::FILE_HEADER."\n";
        if ($imports !== []) {
            $output .= implode("\n", $imports)."\n\n";
        }

        return $output.$body;
    }

    /**
     * Like `resolvePagePropType`, but also recognises JsonResource subclasses
     * and emits sibling-import statements for them.
     *
     * @param  list<array>  $allModels
     * @param  list<array>  $allEnums
     * @param  list<array>  $allResources
     * @param  list<string>  $imports
     */
    protected function resolveAnyTypeString(
        string $type,
        array $allModels,
        array $allEnums,
        array $allResources,
        array &$imports,
        ?string $selfFqcn = null,
    ): string {
        if (class_exists($type) && is_subclass_of($type, JsonResource::class)) {
            $short = $this->resolveResourceName($type, $allResources);
            if ($type !== $selfFqcn) {
                $imports[] = sprintf("import type { %s } from './%s';", $short, $short);
            }

            return $short;
        }

        return $this->resolvePagePropType($type, $allModels, $allEnums, $imports);
    }

    /**
     * @param  list<array>  $allResources
     */
    protected function resolveResourceName(string $fqcn, array $allResources): string
    {
        foreach ($allResources as $allResource) {
            if ($allResource['fqcn'] === $fqcn) {
                return $allResource['name'];
            }
        }

        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
