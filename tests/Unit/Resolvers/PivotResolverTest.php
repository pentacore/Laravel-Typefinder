<?php

declare(strict_types=1);

namespace Tests\Unit\Resolvers;

use Pentacore\Typefinder\Resolvers\PivotResolver;
use PHPUnit\Framework\TestCase;

final class PivotResolverTest extends TestCase
{
    public function test_type_name_is_the_studly_table_name_with_a_pivot_suffix(): void
    {
        $this->assertSame('RoleUserPivot', PivotResolver::typeName('role_user'));
        $this->assertSame('TaggablesPivot', PivotResolver::typeName('taggables'));
    }

    public function test_one_pivot_per_table_from_the_first_relation_that_uses_it(): void
    {
        $pivots = new PivotResolver()->resolve([
            $this->model([
                $this->relation('role_user', 'user_id', 'role_id', withPivot: ['assigned_at'], withTimestamps: true),
                ['type' => 'many', 'name' => 'posts'],
            ]),
            $this->model([$this->relation('role_user', 'role_id', 'user_id')]),
        ]);

        $this->assertSame([[
            'name' => 'RoleUserPivot',
            'columns' => [
                ['name' => 'user_id', 'type' => 'number'],
                ['name' => 'role_id', 'type' => 'number'],
                ['name' => 'assigned_at', 'type' => 'string', 'nullable' => true],
            ],
            'withTimestamps' => true,
        ]], $pivots);
    }

    public function test_a_morph_pivot_has_its_type_column(): void
    {
        $pivots = new PivotResolver()->resolve([
            $this->model([$this->relation('taggables', 'taggable_id', 'tag_id', morphType: 'taggable_type')]),
        ]);

        $this->assertSame(
            [['name' => 'taggable_id', 'type' => 'number'], ['name' => 'tag_id', 'type' => 'number'], ['name' => 'taggable_type', 'type' => 'string']],
            $pivots[0]['columns'],
        );
    }

    public function test_a_relation_without_pivot_details_yields_none(): void
    {
        $this->assertSame([], new PivotResolver()->resolve([$this->model([['type' => 'manyWithPivot', 'name' => 'roles']])]));
    }

    /**
     * @param  list<array<string, mixed>>  $relationships
     * @return array{relationships: list<array<string, mixed>>}
     */
    private function model(array $relationships): array
    {
        return ['relationships' => $relationships];
    }

    /**
     * @param  list<string>  $withPivot
     * @return array<string, mixed>
     */
    private function relation(string $table, string $foreignKey, string $relatedKey, array $withPivot = [], bool $withTimestamps = false, ?string $morphType = null): array
    {
        return ['type' => 'manyWithPivot', 'name' => 'x', 'pivot' => array_filter([
            'table' => $table,
            'foreignKey' => $foreignKey,
            'relatedKey' => $relatedKey,
            'morphType' => $morphType,
            'withPivot' => $withPivot,
            'withTimestamps' => $withTimestamps,
        ], static fn (mixed $value): bool => $value !== null)];
    }
}
