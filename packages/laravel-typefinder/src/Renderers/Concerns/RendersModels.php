<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Renderers\Concerns;

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
    public function renderModel(array $model, array $allEnums, array $allModels): string
    {
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
    public function renderModelFile(array $model, array $allEnums, array $allModels, bool $emitWriteShapes, array $immutable): string
    {
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

        $morphToRel = collect($model['relationships'] ?? [])->firstWhere('type', 'morphTo');

        $genericParam = '';
        if ($morphToRel && ! empty($morphToRel['morphTargets'])) {
            $targetNames = $this->resolveModelNames($morphToRel['morphTargets'], $allModels);
            $union = implode(' | ', $targetNames);
            $genericParam = sprintf('<T extends %s = %s>', $union, $union);

            foreach ($morphToRel['morphTargets'] as $target) {
                $targetName = $this->resolveModelName($target, $allModels);
                $imports[] = sprintf("import type { %s } from './%s';", $targetName, $targetName);
            }
        }

        foreach ($model['columns'] as $column) {
            $typeStr = $this->resolveTypeString($column['type'], $column['nullable'], $allEnums, $imports);
            $lines[] = sprintf('  %s: %s;', $column['name'], TypeScriptRenderer::appendNullable($typeStr, (bool) $column['nullable']));
        }

        foreach ($model['relationships'] ?? [] as $rel) {
            $relLine = $this->renderRelationshipField($rel, $allModels, $imports);
            if ($relLine !== null) {
                $lines[] = $relLine;
            }
        }

        $body = "export type {$model['name']}{$genericParam} = {\n".implode("\n", $lines)."\n};\n";

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
            $typeStr = $this->resolveTypeString($column['type'], $column['nullable'], $allEnums, $imports);
            $optional = $column['nullable'] ? '?' : '';
            $lines[] = sprintf('  %s%s: %s;', $column['name'], $optional, TypeScriptRenderer::appendNullable($typeStr, (bool) $column['nullable']));
        }

        $body = "export type {$model['name']}Create = {\n".implode("\n", $lines)."\n};\n";

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
            $typeStr = $this->resolveTypeString($column['type'], $column['nullable'], $allEnums, $imports);
            $lines[] = sprintf('  %s?: %s;', $column['name'], TypeScriptRenderer::appendNullable($typeStr, (bool) $column['nullable']));
        }

        $body = "export type {$model['name']}Update = {\n".implode("\n", $lines)."\n};\n";

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

        $imports = array_values(array_unique($imports));
        sort($imports);

        $output = self::FILE_HEADER."\n";
        if ($imports !== []) {
            $output .= implode("\n", $imports)."\n\n";
        }

        return $output.implode("\n", $bodies);
    }
}
