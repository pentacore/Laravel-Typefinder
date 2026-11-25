<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Renderers\Concerns;

use Illuminate\Support\Str;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;

/**
 * Model type rendering: the canonical read shape plus the optional
 * {Model}Create / {Model}Update companions, handling nullable→optional
 * on Create, full-partial on Update, morphTo generics, and relationship
 * imports.
 *
 * Mixed into {@see TypeScriptRenderer};
 * relies on the host class's `resolveTypeString`, `resolveModelName`,
 * `resolveModelNames`, `renderRelationshipField`, and `FILE_HEADER`.
 */
trait RendersModels
{
    /**
     * Render a model to a .d.ts file content string (read shape only).
     *
     * @param  list<array>  $allEnums
     * @param  list<array>  $allModels
     */
    public function renderModel(array $model, array $allEnums, array $allModels, array $allResources = []): string
    {
        $this->modelResources = $allResources;

        return $this->assembleFile([$this->buildModelBlock($model, $allEnums, $allModels)]);
    }

    /**
     * @param  list<array>  $allEnums
     * @param  list<array>  $allModels
     */
    public function renderModelCreate(array $model, array $allEnums, array $allModels): string
    {
        return $this->assembleFile([$this->buildModelCreateBlock($model, $allEnums, $allModels)]);
    }

    /**
     * @param  list<array>  $allEnums
     * @param  list<array>  $allModels
     * @param  list<string>  $immutable
     */
    public function renderModelUpdate(array $model, array $allEnums, array $allModels, array $immutable): string
    {
        return $this->assembleFile([$this->buildModelUpdateBlock($model, $allEnums, $allModels, $immutable)]);
    }

    /**
     * Render the unified file content: read shape plus Create/Update companions
     * when $emitWriteShapes is true. Imports are unioned across blocks.
     *
     * @param  list<array>  $allEnums
     * @param  list<array>  $allModels
     * @param  list<string>  $immutable
     */
    public function renderModelFile(array $model, array $allEnums, array $allModels, bool $emitWriteShapes, array $immutable, array $allResources = []): string
    {
        $this->modelResources = $allResources;
        $blocks = [$this->buildModelBlock($model, $allEnums, $allModels)];

        if ($emitWriteShapes) {
            $blocks[] = $this->buildModelCreateBlock($model, $allEnums, $allModels);
            $blocks[] = $this->buildModelUpdateBlock($model, $allEnums, $allModels, $immutable);
        }

        return $this->assembleFile($blocks);
    }

    /**
     * @param  list<array>  $allEnums
     * @param  list<array>  $allModels
     * @return array{imports: list<string>, body: string}
     */
    protected function buildModelBlock(array $model, array $allEnums, array $allModels): array
    {
        $imports = [];
        $lines = [];

        $relationships = $model['relationships'] ?? [];
        $morphToNames = array_column(array_filter($relationships, fn (array $rel): bool => $rel['type'] === 'morphTo'), 'name');

        // One generic per morphTo: `T` when there is one, `TSubject` /
        // `TCauser` when there are more.
        $generics = [];
        foreach ($relationships as $index => $rel) {
            if ($rel['type'] !== 'morphTo') {
                continue;
            }

            $generic = count($morphToNames) === 1 ? 'T' : 'T'.Str::studly($rel['name']);
            $relationships[$index]['generic'] = $generic;

            if (empty($rel['morphTargets'])) {
                // No model declares the inverse morphMany / morphOne, so nothing
                // narrows the generic; still declare it so the field compiles.
                $generics[] = $generic.' = unknown';

                continue;
            }

            $union = implode(' | ', $this->resolveModelNames($rel['morphTargets'], $allModels));
            $generics[] = sprintf('%s extends %s = %s', $generic, $union, $union);

            foreach ($rel['morphTargets'] as $target) {
                $targetName = $this->resolveModelName($target, $allModels);
                $imports[] = sprintf("import type { %s } from './%s';", $targetName, $targetName);
            }
        }

        $genericParam = $generics === [] ? '' : '<'.implode(', ', $generics).'>';

        foreach ($model['columns'] as $column) {
            $typeStr = $this->resolveTypeString($column['type'], $column['nullable'], $allEnums, $imports, 'models', $model['fqcn'] ?? null, $allModels);
            $lines[] = '  '.TypeScriptRenderer::member((string) $column['name'], TypeScriptRenderer::appendNullable($typeStr, (bool) $column['nullable']), ! empty($column['optional'])).';';
        }

        foreach ($relationships as $rel) {
            $relLine = $this->renderRelationshipField($rel, $allModels, $imports);
            if ($relLine !== null) {
                $lines[] = $relLine;
            }
        }

        $body = "export type {$model['name']}{$genericParam} = {\n".implode("\n", $lines)."\n};\n";

        // A self-referencing relation (e.g. parent(): BelongsTo to the same
        // model) would otherwise import the type this file declares (TS2440).
        $selfImport = sprintf("import type { %s } from './%s';", $model['name'], $model['name']);
        $imports = array_values(array_filter($imports, fn (string $import): bool => $import !== $selfImport));

        return ['imports' => $imports, 'body' => $body];
    }

    /**
     * @param  list<array>  $allEnums
     * @param  list<array>  $allModels
     * @return array{imports: list<string>, body: string}
     */
    protected function buildModelCreateBlock(array $model, array $allEnums, array $allModels): array
    {
        $imports = [];
        $lines = [];

        $eligible = array_values(array_filter(
            $model['assignable_columns'] ?? [],
            fn (array $c): bool => empty($c['is_server_filled']),
        ));

        foreach ($eligible as $column) {
            $typeStr = $this->resolveTypeString($column['type'], $column['nullable'], $allEnums, $imports, 'models', $model['fqcn'] ?? null, $allModels);
            // An insert may leave out what is nullable or has a default.
            $optional = $column['nullable'] || ! empty($column['has_default']);
            $lines[] = '  '.TypeScriptRenderer::member((string) $column['name'], TypeScriptRenderer::appendNullable($typeStr, (bool) $column['nullable']), $optional).';';
        }

        $body = $lines === []
            ? "export type {$model['name']}Create = Record<string, never>;\n"
            : "export type {$model['name']}Create = {\n".implode("\n", $lines)."\n};\n";

        return ['imports' => $imports, 'body' => $body];
    }

    /**
     * @param  list<array>  $allEnums
     * @param  list<array>  $allModels
     * @param  list<string>  $immutable
     * @return array{imports: list<string>, body: string}
     */
    protected function buildModelUpdateBlock(array $model, array $allEnums, array $allModels, array $immutable): array
    {
        $imports = [];
        $lines = [];

        $eligible = array_values(array_filter(
            $model['assignable_columns'] ?? [],
            fn (array $c): bool => empty($c['is_server_filled']) && ! in_array($c['name'], $immutable, true),
        ));

        foreach ($eligible as $column) {
            $typeStr = $this->resolveTypeString($column['type'], $column['nullable'], $allEnums, $imports, 'models', $model['fqcn'] ?? null, $allModels);
            $lines[] = '  '.TypeScriptRenderer::member((string) $column['name'], TypeScriptRenderer::appendNullable($typeStr, (bool) $column['nullable']), true).';';
        }

        $body = $lines === []
            ? "export type {$model['name']}Update = Record<string, never>;\n"
            : "export type {$model['name']}Update = {\n".implode("\n", $lines)."\n};\n";

        return ['imports' => $imports, 'body' => $body];
    }

    /**
     * @param  list<array{imports: list<string>, body: string}>  $blocks
     */
    protected function assembleFile(array $blocks): string
    {
        $imports = [];
        $bodies = [];
        foreach ($blocks as $block) {
            foreach ($block['imports'] as $imp) {
                $imports[] = $imp;
            }

            $bodies[] = $block['body'];
        }

        $output = self::FILE_HEADER."\n".self::importBlock($imports);

        return $output.implode("\n", $bodies);
    }
}
