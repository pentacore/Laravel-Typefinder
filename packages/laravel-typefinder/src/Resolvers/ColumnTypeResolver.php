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
        // Float / decimal family
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
        // Boolean
        'boolean' => 'boolean',
        'bool' => 'boolean',
        // Date / time
        'date' => 'string',
        'datetime' => 'string',
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
    ];

    public function resolve(string $columnType, bool $nullable): string
    {
        $tsType = self::TYPE_MAP[$columnType] ?? 'unknown';

        if ($nullable) {
            return $tsType.' | null';
        }

        return $tsType;
    }
}
