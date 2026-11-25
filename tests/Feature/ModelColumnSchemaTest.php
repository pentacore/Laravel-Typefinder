<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Models\RemoteThing;
use Tests\Fixtures\Models\TablelessThing;
use Tests\TestCase;

/** Where a model's columns come from and what their types are (#140, #141, #142, #147). */
final class ModelColumnSchemaTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.connections.other', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    public function test_decimal_and_timestamp_casts_match_their_serialised_form(): void
    {
        $castTypeResolver = new CastTypeResolver;

        $this->assertSame('string', $castTypeResolver->resolve('decimal:2'));
        $this->assertSame('number', $castTypeResolver->resolve('timestamp'));
    }

    public function test_uncast_exact_numerics_are_strings_on_drivers_that_return_strings(): void
    {
        $columnTypeResolver = new ColumnTypeResolver;

        foreach (['mysql', 'mariadb', 'pgsql', 'sqlsrv'] as $driver) {
            $this->assertSame('string', $columnTypeResolver->resolve('decimal', false, $driver), $driver);
            $this->assertSame('string | null', $columnTypeResolver->resolve('numeric', true, $driver), $driver);
            $this->assertSame('number', $columnTypeResolver->resolve('float8', false, $driver), $driver);
        }

        $this->assertSame('number', $columnTypeResolver->resolve('decimal', false, 'sqlite'));
        $this->assertSame('number', $columnTypeResolver->resolve('numeric', false, 'sqlite'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function columnTypes(): iterable
    {
        foreach (['nvarchar', 'nchar', 'ntext', 'datetime2', 'datetimeoffset', 'uniqueidentifier', 'money'] as $type) {
            yield 'sqlsrv '.$type => [$type, 'string'];
        }

        foreach (['citext', 'inet', 'cidr', 'macaddr', 'money', 'xml', 'tsvector'] as $type) {
            yield 'pgsql '.$type => [$type, 'string'];
        }

        // pdo_pgsql returns an array column as its literal (`{1,2}`), a string.
        foreach (['_int4', '_int8', '_text', '_varchar'] as $type) {
            yield 'pgsql array '.$type => [$type, 'string'];
        }

        yield 'mysql set' => ['set', 'string'];
        yield 'mysql upper-case name' => ['NVARCHAR', 'string'];
    }

    #[DataProvider('columnTypes')]
    public function test_maps_database_specific_column_types(string $typeName, string $expected): void
    {
        $columnTypeResolver = new ColumnTypeResolver;

        $this->assertSame($expected, $columnTypeResolver->resolve($typeName, false));
        $this->assertTrue($columnTypeResolver->recognises($typeName));
    }

    public function test_spatial_types_are_unknown_without_being_unrecognised(): void
    {
        $columnTypeResolver = new ColumnTypeResolver;

        foreach (['geometry', 'geography', 'point', 'polygon', 'multipolygon', 'geomcollection'] as $type) {
            $this->assertSame('unknown', $columnTypeResolver->resolve($type, false), $type);
            $this->assertTrue($columnTypeResolver->recognises($type), $type);
        }

        $this->assertFalse($columnTypeResolver->recognises('cube'));
    }

    public function test_a_postgres_enum_type_is_a_string(): void
    {
        $columnTypeResolver = new ColumnTypeResolver;

        $this->assertSame('string', $columnTypeResolver->resolve('mood', false, 'pgsql', ['mood']));
        $this->assertFalse($columnTypeResolver->recognises('mood', 'pgsql'));
    }

    public function test_reads_the_columns_from_the_models_own_connection(): void
    {
        Schema::connection('other')->create('remote_things', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });

        $result = $this->extractor()->extract(RemoteThing::class);

        $this->assertSame(['id', 'label'], array_column($result['columns'], 'name'));
        $this->assertSame([], $this->warnings);
    }

    public function test_warns_when_the_table_does_not_exist(): void
    {
        $result = $this->extractor()->extract(TablelessThing::class);

        $this->assertSame([], $result['columns']);
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString(TablelessThing::class.': table "no_such_table" not found', $this->warnings[0]);
    }

    public function test_reads_postgres_enum_types_once_per_connection(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getName')->andReturn('pg');
        $schemaBuilder = Mockery::mock(Builder::class);
        $schemaBuilder->shouldReceive('getConnection')->andReturn($connection);
        $schemaBuilder->shouldReceive('getTypes')->once()->andReturn([
            ['name' => 'mood', 'type' => 'enum'],
            ['name' => 'point3', 'type' => 'composite'],
        ]);

        $extractor = new class(new ColumnTypeResolver, new CastTypeResolver) extends ModelExtractor
        {
            /** @return list<string> */
            public function enumTypesOf(Builder $schemaBuilder, string $driver): array
            {
                return $this->enumTypes($schemaBuilder, $driver);
            }
        };

        $this->assertSame([], $extractor->enumTypesOf($schemaBuilder, 'mysql'));
        $this->assertSame(['mood'], $extractor->enumTypesOf($schemaBuilder, 'pgsql'));
        $this->assertSame(['mood'], $extractor->enumTypesOf($schemaBuilder, 'pgsql'));
    }

    private function extractor(): ModelExtractor
    {
        return new ModelExtractor(new ColumnTypeResolver, new CastTypeResolver, function (string $message): void {
            $this->warnings[] = $message;
        });
    }
}
