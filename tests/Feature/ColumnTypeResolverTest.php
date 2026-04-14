<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Tests\TestCase;

final class ColumnTypeResolverTest extends TestCase
{
    private ColumnTypeResolver $columnTypeResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->columnTypeResolver = new ColumnTypeResolver;
    }

    public function test_integer_types(): void
    {
        foreach (['bigint', 'integer', 'smallint', 'tinyint', 'mediumint'] as $type) {
            $this->assertSame('number', $this->columnTypeResolver->resolve($type, false), 'Failed for: '.$type);
        }
    }

    public function test_float_types(): void
    {
        foreach (['decimal', 'float', 'double'] as $type) {
            $this->assertSame('number', $this->columnTypeResolver->resolve($type, false), 'Failed for: '.$type);
        }
    }

    public function test_string_types(): void
    {
        foreach (['varchar', 'char', 'text', 'mediumtext', 'longtext', 'tinytext'] as $type) {
            $this->assertSame('string', $this->columnTypeResolver->resolve($type, false), 'Failed for: '.$type);
        }
    }

    public function test_boolean_type(): void
    {
        $this->assertSame('boolean', $this->columnTypeResolver->resolve('boolean', false));
    }

    public function test_date_types(): void
    {
        foreach (['date', 'datetime', 'timestamp', 'time'] as $type) {
            $this->assertSame('string', $this->columnTypeResolver->resolve($type, false), 'Failed for: '.$type);
        }
    }

    public function test_json_types(): void
    {
        foreach (['json', 'jsonb'] as $type) {
            $this->assertSame('unknown', $this->columnTypeResolver->resolve($type, false), 'Failed for: '.$type);
        }
    }

    public function test_binary_types(): void
    {
        foreach (['blob', 'binary'] as $type) {
            $this->assertSame('string', $this->columnTypeResolver->resolve($type, false), 'Failed for: '.$type);
        }
    }

    public function test_uuid_type(): void
    {
        $this->assertSame('string', $this->columnTypeResolver->resolve('uuid', false));
    }

    public function test_enum_type(): void
    {
        $this->assertSame('string', $this->columnTypeResolver->resolve('enum', false));
    }

    public function test_unknown_type(): void
    {
        $this->assertSame('unknown', $this->columnTypeResolver->resolve('geometry', false));
    }

    public function test_nullable_adds_null_union(): void
    {
        $this->assertSame('string | null', $this->columnTypeResolver->resolve('varchar', true));
        $this->assertSame('number | null', $this->columnTypeResolver->resolve('integer', true));
        $this->assertSame('boolean | null', $this->columnTypeResolver->resolve('boolean', true));
    }
}
