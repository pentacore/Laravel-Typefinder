<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Renderers\Concerns;

use Pentacore\Typefinder\Renderers\TypeScriptRenderer;

/**
 * Resource type rendering across the declaration kinds: explicit shape,
 * PHPDoc-derived shape tree, model extension (`Omit<…> & { … }`), and
 * name-convention passthrough.
 *
 * Class-strings in `shape` and `extend` resolve through the host class's
 * `resolveClassString`, imported relative to `resources/`.
 *
 * Mixed into {@see TypeScriptRenderer};
 * relies on the host class's `resolveModelName`, `resolveClassString`,
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
                $resolved = $this->resolveClassString((string) $type, 'resources', $allModels, $allEnums, $allResources, $imports, $resource['fqcn']);
                $lines[] = sprintf('  %s: %s;', TypeScriptRenderer::memberKey((string) $fieldName), $resolved);
            }

            return $this->assembleResourceFile($imports, "export type {$name} = {\n".implode("\n", $lines)."\n};\n");
        }

        // A type tree: documented (`phpdoc`), a collection's items
        // (`collection`) or read from the literal keys of `toArray()` (`inferred`).
        if (in_array($shape['kind'], ['phpdoc', 'collection', 'inferred'], true)) {
            return $this->renderPhpdocResource($resource, $allModels, $allEnums, $allResources);
        }

        // `unknown` (with a warning) when the model gets no generated type.
        $modelShort = $this->resolveClassReference(ltrim((string) $shape['model'], '\\'), 'resources', $allModels, $allEnums, $allResources, $imports)
            ?? $this->resolveModelName($shape['model'], $allModels);

        $body = sprintf('export type %s = ', $name);

        $omit = $shape['omit'];
        $extend = $shape['extend'];

        if ($modelShort === 'unknown') {
            $body .= $extend === [] ? 'unknown' : 'Record<string, unknown>';
        } elseif ($omit !== []) {
            $omitUnion = implode(' | ', array_map(TypeScriptRenderer::stringLiteral(...), $omit));
            $body .= sprintf('Omit<%s, %s>', $modelShort, $omitUnion);
        } else {
            $body .= $modelShort;
        }

        if ($extend !== []) {
            $parts = [];
            foreach ($extend as $fieldName => $type) {
                $resolved = $this->resolveClassString((string) $type, 'resources', $allModels, $allEnums, $allResources, $imports, $resource['fqcn']);
                $parts[] = sprintf('%s: %s', TypeScriptRenderer::memberKey((string) $fieldName), $resolved);
            }

            $body .= ' & { '.implode('; ', $parts).' }';
        }

        $body .= ";\n";

        return $this->assembleResourceFile($imports, $body);
    }

    /**
     * @param  array{name: string, fqcn: string, shape: array}  $resource
     * @param  list<array>  $allModels
     * @param  list<array>  $allEnums
     * @param  list<array>  $allResources
     */
    protected function renderPhpdocResource(array $resource, array $allModels, array $allEnums, array $allResources): string
    {
        $imports = [];
        $type = $resource['shape']['type'];

        if ($type['kind'] === 'object') {
            $lines = [];
            foreach ($type['fields'] as $field) {
                $lines[] = '  '.$this->renderObjectField(
                    $field,
                    $this->renderTypeNode($field['type'], $imports, $resource['fqcn'], $allModels, $allEnums, $allResources),
                ).';';
            }

            if ($type['open']) {
                $lines[] = '  [key: string]: unknown;';
            }

            $body = $lines === []
                ? "export type {$resource['name']} = {};\n"
                : "export type {$resource['name']} = {\n".implode("\n", $lines)."\n};\n";

            return $this->assembleResourceFile($imports, $body);
        }

        $rendered = $this->renderTypeNode($type, $imports, $resource['fqcn'], $allModels, $allEnums, $allResources);

        return $this->assembleResourceFile($imports, sprintf("export type %s = %s;\n", $resource['name'], $rendered));
    }

    /**
     * Render a type-tree node (see PhpDocTypeMapper) to a TypeScript type string.
     * Class leaves resolve through `resolveClassReference`, imported relative to `resources/`.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $imports  Collected import statements (by reference).
     * @param  list<array>  $allModels
     * @param  list<array>  $allEnums
     * @param  list<array>  $allResources
     */
    protected function renderTypeNode(array $node, array &$imports, string $selfFqcn, array $allModels, array $allEnums, array $allResources): string
    {
        // A closure (not an arrow fn) so `$imports` is captured by reference.
        $render = function (array $child) use (&$imports, $selfFqcn, $allModels, $allEnums, $allResources): string {
            return $this->renderTypeNode($child, $imports, $selfFqcn, $allModels, $allEnums, $allResources);
        };

        switch ($node['kind']) {
            case 'ts':
                return (string) $node['ts'];
            case 'model':
            case 'enum':
            case 'resource':
                return $this->resolveClassReference(ltrim((string) $node['fqcn'], '\\'), 'resources', $allModels, $allEnums, $allResources, $imports, $selfFqcn)
                    ?? 'unknown';
            case 'property':
                return $this->modelProperty((string) $node['fqcn'], (string) $node['name'], $imports, $selfFqcn, $allModels, $allEnums, $allResources);
            case 'array':
                $element = $render($node['of']);

                return ($this->hasTopLevelOperator($element) ? "({$element})" : $element).'[]';
            case 'record':
                return sprintf('Record<string, %s>', $render($node['of']));
            case 'tuple':
                return '['.implode(', ', array_map(function (array $item) use ($render): string {
                    $text = $render($item['type']);

                    if (! $item['optional']) {
                        return $text;
                    }

                    return ($this->hasTopLevelOperator($text) ? "({$text})" : $text).'?';
                }, $node['items'])).']';
            case 'union':
                $members = [];
                foreach ($this->flattenUnion($node) as $member) {
                    $members[] = $render($member);
                }

                return implode(' | ', array_values(array_unique($members)));
            case 'intersection':
                return implode(' & ', array_map(function (array $member) use ($render): string {
                    $text = $render($member);

                    return $this->hasTopLevelOperator($text, '|') ? "({$text})" : $text;
                }, $node['of']));
            case 'object':
                $parts = array_map(
                    fn (array $field): string => $this->renderObjectField($field, $render($field['type'])),
                    $node['fields'],
                );
                if ($node['open']) {
                    $parts[] = '[key: string]: unknown';
                }

                return $parts === [] ? '{}' : '{ '.implode('; ', $parts).' }';
            default:
                return 'unknown';
        }
    }

    /**
     * `Model['col']` for a column of a generated model's type; `unknown`
     * for anything else (a relation, an accessor the type lacks).
     *
     * @param  list<string>  $imports
     * @param  list<array>  $allModels
     * @param  list<array>  $allEnums
     * @param  list<array>  $allResources
     */
    private function modelProperty(string $model, string $column, array &$imports, string $selfFqcn, array $allModels, array $allEnums, array $allResources): string
    {
        foreach ($allModels as $entry) {
            if (ltrim((string) $entry['fqcn'], '\\') !== ltrim($model, '\\')) {
                continue;
            }

            if (! in_array($column, array_map(strval(...), array_column($entry['columns'] ?? [], 'name')), true)) {
                return 'unknown';
            }

            $reference = $this->resolveClassReference(ltrim($model, '\\'), 'resources', $allModels, $allEnums, $allResources, $imports, $selfFqcn);

            return $reference === null || $reference === 'unknown'
                ? 'unknown'
                : $reference.'['.TypeScriptRenderer::stringLiteral($column).']';
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private function flattenUnion(array $node): array
    {
        $flat = [];
        foreach ($node['of'] as $member) {
            array_push($flat, ...($member['kind'] === 'union' ? $this->flattenUnion($member) : [$member]));
        }

        return $flat;
    }

    /**
     * One object member (`key?: T`), shared by the multi-line top level and inline nested objects.
     *
     * @param  array{name: string, optional: bool}  $field
     */
    private function renderObjectField(array $field, string $renderedType): string
    {
        return TypeScriptRenderer::member((string) $field['name'], $renderedType, (bool) $field['optional']);
    }

    /**
     * Whether rendered TS contains ` | ` / ` & ` outside any brackets or quotes.
     */
    private function hasTopLevelOperator(string $text, string $operators = '|&'): bool
    {
        $depth = 0;
        $quote = null;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'") {
                $quote = $char;
            } elseif (str_contains('{[(<', $char)) {
                $depth++;
            } elseif (str_contains('}])>', $char)) {
                $depth--;
            } elseif ($depth === 0 && str_contains($operators, $char) && ($text[$i - 1] ?? '') === ' ' && ($text[$i + 1] ?? '') === ' ') {
                return true;
            }
        }

        return false;
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
