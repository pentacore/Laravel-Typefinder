<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Resolvers;

/**
 * Derives the pivot types from the `belongsToMany` / `morphToMany` relations
 * of the extracted models: one per pivot table.
 */
final class PivotResolver
{
    /**
     * The type name of a pivot table: `role_user` is `RoleUserPivot`.
     */
    public static function typeName(string $table): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $table))).'Pivot';
    }

    /**
     * The first relation that uses a table decides its pivot's columns.
     *
     * @param  list<array{relationships: list<array<string, mixed>>}>  $allModels
     * @return list<array{name: string, columns: list<array{name: string, type: string, nullable?: bool}>, withTimestamps: bool}>
     */
    public function resolve(array $allModels): array
    {
        $pivots = [];
        $seen = [];

        foreach ($allModels as $allModel) {
            foreach ($allModel['relationships'] as $rel) {
                if ($rel['type'] !== 'manyWithPivot') {
                    continue;
                }

                if (! isset($rel['pivot'])) {
                    continue;
                }

                $tableName = $rel['pivot']['table'];
                if (isset($seen[$tableName])) {
                    continue;
                }

                $seen[$tableName] = true;

                $pivotName = self::typeName($tableName);

                $columns = [
                    ['name' => $rel['pivot']['foreignKey'], 'type' => 'number'],
                    ['name' => $rel['pivot']['relatedKey'], 'type' => 'number'],
                ];

                if (isset($rel['pivot']['morphType'])) {
                    $columns[] = ['name' => $rel['pivot']['morphType'], 'type' => 'string'];
                }

                foreach ($rel['pivot']['withPivot'] as $col) {
                    $columns[] = ['name' => $col, 'type' => 'string', 'nullable' => true];
                }

                $pivots[] = [
                    'name' => $pivotName,
                    'columns' => $columns,
                    'withTimestamps' => $rel['pivot']['withTimestamps'],
                ];
            }
        }

        return $pivots;
    }
}
