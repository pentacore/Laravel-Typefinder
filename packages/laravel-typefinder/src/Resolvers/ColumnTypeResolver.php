<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Resolvers;

class ColumnTypeResolver
{
    /** @var array<string, string> */
    protected const array TYPE_MAP = [
        'bigint' => 'number',
        'integer' => 'number',
        'smallint' => 'number',
        'tinyint' => 'number',
        'mediumint' => 'number',
        'decimal' => 'number',
        'float' => 'number',
        'double' => 'number',
        'varchar' => 'string',
        'char' => 'string',
        'text' => 'string',
        'mediumtext' => 'string',
        'longtext' => 'string',
        'tinytext' => 'string',
        'boolean' => 'boolean',
        'date' => 'string',
        'datetime' => 'string',
        'timestamp' => 'string',
        'time' => 'string',
        'json' => 'unknown',
        'jsonb' => 'unknown',
        'blob' => 'string',
        'binary' => 'string',
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
