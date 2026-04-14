<?php

namespace Pentacore\Typefinder\Renderers;

class TypeScriptRenderer
{
    /**
     * Render an enum to a .d.ts file content string.
     */
    public function renderEnum(array $enum): string
    {
        $name = $enum['name'];
        $values = $enum['values'];
        $backingType = $enum['backingType'];

        $valueStrings = array_map(function ($value) use ($backingType) {
            if ($backingType === 'string') {
                return "'{$value}'";
            }

            return (string) $value;
        }, $values);

        $union = implode(' | ', $valueStrings);

        return "export type {$name} = {$union};\n";
    }

    /**
     * Render a model to a .d.ts file content string.
     *
     * @param  list<array>  $allEnums
     * @param  list<array>  $allModels
     */
    public function renderModel(array $model, array $allEnums, array $allModels): string
    {
        $imports = [];
        $lines = [];

        $morphToRel = collect($model['relationships'] ?? [])->firstWhere('type', 'morphTo');

        $genericParam = '';
        if ($morphToRel && ! empty($morphToRel['morphTargets'])) {
            $targetNames = $this->resolveModelNames($morphToRel['morphTargets'], $allModels);
            $union = implode(' | ', $targetNames);
            $genericParam = "<T extends {$union} = {$union}>";

            foreach ($morphToRel['morphTargets'] as $target) {
                $targetName = $this->resolveModelName($target, $allModels);
                $imports[] = "import type { {$targetName} } from './{$targetName}';";
            }
        }

        foreach ($model['columns'] as $column) {
            $typeStr = $this->resolveTypeString($column['type'], $column['nullable'], $allEnums, $imports);
            $nullUnion = $column['nullable'] ? ' | null' : '';
            $lines[] = "  {$column['name']}: {$typeStr}{$nullUnion};";
        }

        foreach ($model['relationships'] ?? [] as $rel) {
            $relLine = $this->renderRelationshipField($rel, $allModels, $imports);
            if ($relLine !== null) {
                $lines[] = $relLine;
            }
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        $output = '';
        if (! empty($imports)) {
            $output .= implode("\n", $imports)."\n\n";
        }
        $output .= "export type {$model['name']}{$genericParam} = {\n";
        $output .= implode("\n", $lines)."\n";
        $output .= "};\n";

        return $output;
    }

    /**
     * Render the Create companion type (omits server-filled fields and relationships).
     *
     * @param  list<array>  $allEnums
     * @param  list<array>  $allModels
     */
    public function renderModelCreate(array $model, array $allEnums, array $allModels): string
    {
        $imports = [];
        $lines = [];

        $eligible = array_values(array_filter(
            $model['assignable_columns'] ?? [],
            fn ($c) => empty($c['is_server_filled']),
        ));

        foreach ($eligible as $column) {
            $typeStr = $this->resolveTypeString($column['type'], $column['nullable'], $allEnums, $imports);
            $optional = $column['nullable'] ? '?' : '';
            $nullUnion = $column['nullable'] ? ' | null' : '';
            $lines[] = "  {$column['name']}{$optional}: {$typeStr}{$nullUnion};";
        }

        return $this->assembleType("{$model['name']}Create", $imports, $lines);
    }

    /**
     * Render the Update companion type: every assignable field becomes optional,
     * immutable-on-update fields are dropped. Relationships are excluded.
     *
     * @param  list<array>  $allEnums
     * @param  list<array>  $allModels
     * @param  list<string>  $immutable
     */
    public function renderModelUpdate(array $model, array $allEnums, array $allModels, array $immutable): string
    {
        $imports = [];
        $lines = [];

        $eligible = array_values(array_filter(
            $model['assignable_columns'] ?? [],
            fn ($c) => empty($c['is_server_filled']) && ! in_array($c['name'], $immutable, true),
        ));

        foreach ($eligible as $column) {
            $typeStr = $this->resolveTypeString($column['type'], $column['nullable'], $allEnums, $imports);
            $nullUnion = $column['nullable'] ? ' | null' : '';
            $lines[] = "  {$column['name']}?: {$typeStr}{$nullUnion};";
        }

        return $this->assembleType("{$model['name']}Update", $imports, $lines);
    }

    /**
     * @param  list<string>  $imports
     * @param  list<string>  $lines
     */
    protected function assembleType(string $name, array $imports, array $lines): string
    {
        $imports = array_values(array_unique($imports));
        sort($imports);

        $output = '';
        if (! empty($imports)) {
            $output .= implode("\n", $imports)."\n\n";
        }
        $output .= "export type {$name} = {\n";
        $output .= implode("\n", $lines)."\n";
        $output .= "};\n";

        return $output;
    }

    /**
     * Render a request to a .d.ts file content string.
     *
     * @param  list<array>  $allEnums
     */
    public function renderRequest(array $request, array $allEnums, bool $extractNested = false): string
    {
        $imports = [];
        $extractedTypes = [];
        $lines = [];

        foreach ($request['fields'] as $field) {
            $this->renderRequestField($field, $request['name'], $allEnums, $imports, $lines, $extractedTypes, $extractNested);
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        $output = '';
        if (! empty($imports)) {
            $output .= implode("\n", $imports)."\n\n";
        }

        foreach ($extractedTypes as $extracted) {
            $output .= $extracted."\n\n";
        }

        $output .= "export type {$request['name']} = {\n";
        $output .= implode("\n", $lines)."\n";
        $output .= "};\n";

        return $output;
    }

    /**
     * Render a pivot type to a .d.ts file content string.
     */
    public function renderPivot(array $pivot): string
    {
        $lines = [];

        foreach ($pivot['columns'] as $column) {
            $nullable = $column['nullable'] ?? false;
            $nullUnion = $nullable ? ' | null' : '';
            $lines[] = "  {$column['name']}: {$column['type']}{$nullUnion};";
        }

        if ($pivot['withTimestamps'] ?? false) {
            $lines[] = '  created_at: string | null;';
            $lines[] = '  updated_at: string | null;';
        }

        return "export type {$pivot['name']} = {\n".implode("\n", $lines)."\n};\n";
    }

    /**
     * Render a barrel index.d.ts file.
     *
     * @param  list<string>  $typeNames
     */
    public function renderBarrelIndex(array $typeNames): string
    {
        $lines = array_map(
            fn (string $name) => "export type { {$name} } from './{$name}';",
            $typeNames
        );

        return implode("\n", $lines)."\n";
    }

    /**
     * Render the top-level barrel index.d.ts file.
     *
     * @param  list<string>  $categories
     */
    public function renderTopLevelBarrel(array $categories): string
    {
        $lines = array_map(
            fn (string $cat) => "export type * from './{$cat}';",
            $categories
        );

        return implode("\n", $lines)."\n";
    }

    /**
     * Resolve a type value to a TypeScript type string.
     *
     * @param  list<array>  $allEnums
     * @param  list<string>  $imports  (passed by reference)
     */
    protected function resolveTypeString(mixed $type, bool $nullable, array $allEnums, array &$imports): string
    {
        if (is_string($type)) {
            return $type;
        }

        if (is_array($type)) {
            if (isset($type['enum'])) {
                $enumName = $this->resolveEnumName($type['enum'], $allEnums);
                $imports[] = "import type { {$enumName} } from '../enums';";

                return $enumName;
            }

            if (isset($type['enumCollection'])) {
                $enumName = $this->resolveEnumName($type['enumCollection'], $allEnums);
                $imports[] = "import type { {$enumName} } from '../enums';";

                return $enumName.'[]';
            }

            if (isset($type['in'])) {
                $values = array_map(fn ($v) => "'{$v}'", $type['in']);

                return implode(' | ', $values);
            }

            if (isset($type['anyOf'])) {
                return implode(' | ', array_unique($type['anyOf']));
            }
        }

        return 'unknown';
    }

    /**
     * Render a relationship as a type field line.
     */
    protected function renderRelationshipField(array $rel, array $allModels, array &$imports): ?string
    {
        $name = $rel['name'];

        switch ($rel['type']) {
            case 'one':
            case 'belongsTo':
                $relatedName = $this->resolveModelName($rel['related'], $allModels);
                $imports[] = "import type { {$relatedName} } from './{$relatedName}';";

                return "  {$name}?: {$relatedName} | null;";

            case 'many':
                $relatedName = $this->resolveModelName($rel['related'], $allModels);
                $imports[] = "import type { {$relatedName} } from './{$relatedName}';";

                return "  {$name}?: {$relatedName}[];";

            case 'manyWithPivot':
                $relatedName = $this->resolveModelName($rel['related'], $allModels);
                $imports[] = "import type { {$relatedName} } from './{$relatedName}';";
                $pivotName = $this->generatePivotName($rel['pivot']['table'] ?? $name);
                $imports[] = "import type { {$pivotName} } from '../pivots';";

                return "  {$name}?: ({$relatedName} & { pivot: {$pivotName} })[];";

            case 'morphTo':
                return "  {$name}?: T | null;";

            default:
                return null;
        }
    }

    /**
     * Render a request field (top-level line only; nested recursion handled here).
     */
    protected function renderRequestField(
        array $field,
        string $requestName,
        array $allEnums,
        array &$imports,
        array &$lines,
        array &$extractedTypes,
        bool $extractNested,
        int $indent = 1,
    ): void {
        $prefix = str_repeat('  ', $indent);
        $optional = $field['required'] ? '' : '?';
        $nullUnion = $field['nullable'] ? ' | null' : '';

        if ($field['type'] === 'object' && isset($field['children'])) {
            if ($extractNested) {
                $nestedName = $requestName.ucfirst((string) $field['name']);
                $nestedLines = [];
                foreach ($field['children'] as $child) {
                    $childOptional = $child['required'] ? '' : '?';
                    $childNull = $child['nullable'] ? ' | null' : '';
                    $childType = $this->resolveTypeString($child['type'], $child['nullable'], $allEnums, $imports);
                    $nestedLines[] = "  {$child['name']}{$childOptional}: {$childType}{$childNull};";
                }
                $extractedTypes[] = "export type {$nestedName} = {\n".implode("\n", $nestedLines)."\n};";
                $lines[] = "{$prefix}{$field['name']}{$optional}: {$nestedName}{$nullUnion};";
            } else {
                $lines[] = "{$prefix}{$field['name']}{$optional}: {";
                foreach ($field['children'] as $child) {
                    $childOptional = $child['required'] ? '' : '?';
                    $childNull = $child['nullable'] ? ' | null' : '';
                    $childType = $this->resolveTypeString($child['type'], $child['nullable'], $allEnums, $imports);
                    $lines[] = "{$prefix}  {$child['name']}{$childOptional}: {$childType}{$childNull};";
                }
                $lines[] = "{$prefix}}{$nullUnion};";
            }
        } else {
            $typeStr = $this->resolveTypeString($field['type'], $field['nullable'], $allEnums, $imports);
            $lines[] = "{$prefix}{$field['name']}{$optional}: {$typeStr}{$nullUnion};";
        }
    }

    protected function resolveEnumName(string $fqcn, array $allEnums): string
    {
        foreach ($allEnums as $enum) {
            if ($enum['fqcn'] === $fqcn) {
                return $enum['name'];
            }
        }

        $parts = explode('\\', $fqcn);

        return end($parts);
    }

    protected function resolveModelName(string $fqcn, array $allModels): string
    {
        foreach ($allModels as $model) {
            if ($model['fqcn'] === $fqcn) {
                return $model['name'];
            }
        }

        $parts = explode('\\', $fqcn);

        return end($parts);
    }

    /**
     * @return list<string>
     */
    protected function resolveModelNames(array $fqcns, array $allModels): array
    {
        return array_map(fn (string $fqcn) => $this->resolveModelName($fqcn, $allModels), $fqcns);
    }

    protected function generatePivotName(string $tableName): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $tableName))).'Pivot';
    }
}
