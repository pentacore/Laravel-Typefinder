<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Resolvers;

class ColumnTypeResolver
{
    /** @var array<string, string> */
    protected const array TYPE_MAP = [
        // Integer family — SQL-standard / MySQL / SQLite names
        'bigint' => 'number',
        'int' => 'number',
        'integer' => 'number',
        'smallint' => 'number',
        'tinyint' => 'number',
        'mediumint' => 'number',
        'year' => 'number',
        'bit' => 'number',
        // Integer family — PostgreSQL pg_type.typname
        'int2' => 'number',
        'int4' => 'number',
        'int8' => 'number',
        // Float / decimal family (decimal / numeric: see EXACT_NUMERIC_TYPES)
        'decimal' => 'number',
        'numeric' => 'number',
        'float' => 'number',
        'double' => 'number',
        'real' => 'number',
        'float4' => 'number',
        'float8' => 'number',
        // String family
        'varchar' => 'string',
        'char' => 'string',
        'bpchar' => 'string',
        'text' => 'string',
        'mediumtext' => 'string',
        'longtext' => 'string',
        'tinytext' => 'string',
        'set' => 'string',
        // String family — SQL Server
        'nvarchar' => 'string',
        'nchar' => 'string',
        'ntext' => 'string',
        'uniqueidentifier' => 'string',
        // String family — PostgreSQL
        'citext' => 'string',
        'inet' => 'string',
        'cidr' => 'string',
        'macaddr' => 'string',
        'macaddr8' => 'string',
        'xml' => 'string',
        'tsvector' => 'string',
        'tsquery' => 'string',
        // Money: the drivers return it formatted, as a string.
        'money' => 'string',
        'smallmoney' => 'string',
        // Boolean
        'boolean' => 'boolean',
        'bool' => 'boolean',
        // Date / time
        'date' => 'string',
        'datetime' => 'string',
        'datetime2' => 'string',
        'datetimeoffset' => 'string',
        'smalldatetime' => 'string',
        'timestamp' => 'string',
        'timestamptz' => 'string',
        'time' => 'string',
        'timetz' => 'string',
        'interval' => 'string',
        // JSON
        'json' => 'unknown',
        'jsonb' => 'unknown',
        // Binary
        'blob' => 'string',
        'tinyblob' => 'string',
        'mediumblob' => 'string',
        'longblob' => 'string',
        'binary' => 'string',
        'varbinary' => 'string',
        'bytea' => 'string',
        // Misc
        'uuid' => 'string',
        'enum' => 'string',
        // Spatial: binary geometry no JSON shape describes; known, so no warning.
        'geometry' => 'unknown',
        'geography' => 'unknown',
        'point' => 'unknown',
        'linestring' => 'unknown',
        'polygon' => 'unknown',
        'multipoint' => 'unknown',
        'multilinestring' => 'unknown',
        'multipolygon' => 'unknown',
        'geometrycollection' => 'unknown',
        'geomcollection' => 'unknown',
    ];

    /**
     * Exact numeric types, which the MySQL, PostgreSQL and SQL Server PDO
     * drivers return as strings (to keep their precision); SQLite returns a
     * number.
     *
     * @var list<string>
     */
    protected const array EXACT_NUMERIC_TYPES = ['decimal', 'numeric'];

    /** @var list<string> */
    protected const array STRING_DECIMAL_DRIVERS = ['mysql', 'mariadb', 'pgsql', 'sqlsrv'];

    /**
     * @param  string|null  $driver  the connection's driver name; decides how exact numerics come back
     * @param  list<string>  $enumTypes  user-defined enum type names (PostgreSQL `CREATE TYPE … AS ENUM`)
     */
    public function resolve(string $columnType, bool $nullable, ?string $driver = null, array $enumTypes = []): string
    {
        $tsType = $this->baseType($columnType, $driver, $enumTypes) ?? 'unknown';

        // `unknown` already admits null.
        if ($nullable && $tsType !== 'unknown') {
            return $tsType.' | null';
        }

        return $tsType;
    }

    /**
     * Whether the column type has a mapping, even one to `unknown`.
     *
     * @param  list<string>  $enumTypes
     */
    public function recognises(string $columnType, ?string $driver = null, array $enumTypes = []): bool
    {
        return $this->baseType($columnType, $driver, $enumTypes) !== null;
    }

    /**
     * @param  list<string>  $enumTypes
     */
    protected function baseType(string $columnType, ?string $driver, array $enumTypes): ?string
    {
        $columnType = strtolower($columnType);

        if (in_array($columnType, self::EXACT_NUMERIC_TYPES, true) && in_array($driver, self::STRING_DECIMAL_DRIVERS, true)) {
            return 'string';
        }

        if (isset(self::TYPE_MAP[$columnType])) {
            return self::TYPE_MAP[$columnType];
        }

        if (in_array($columnType, array_map(strtolower(...), $enumTypes), true)) {
            return 'string';
        }

        // PostgreSQL array types (`_int4`, `_text`): pdo_pgsql returns the
        // array literal (`{1,2,3}`) as a string, and Eloquent does not parse it.
        if (str_starts_with($columnType, '_')) {
            return 'string';
        }

        return null;
    }
}
