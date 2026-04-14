<?php

namespace Tests\Feature;

use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Tests\TestCase;

class ColumnTypeResolverTest extends TestCase
{
    private ColumnTypeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ColumnTypeResolver;
    }

    public function test_integer_types(): void
    {
        foreach (['bigint', 'integer', 'smallint', 'tinyint', 'mediumint'] as $type) {
            $this->assertSame('number', $this->resolver->resolve($type, false), "Failed for: $type");
        }
    }

    public function test_float_types(): void
    {
        foreach (['decimal', 'float', 'double'] as $type) {
            $this->assertSame('number', $this->resolver->resolve($type, false), "Failed for: $type");
        }
    }

    public function test_string_types(): void
    {
        foreach (['varchar', 'char', 'text', 'mediumtext', 'longtext', 'tinytext'] as $type) {
            $this->assertSame('string', $this->resolver->resolve($type, false), "Failed for: $type");
        }
    }

    public function test_boolean_type(): void
    {
        $this->assertSame('boolean', $this->resolver->resolve('boolean', false));
    }

    public function test_date_types(): void
    {
        foreach (['date', 'datetime', 'timestamp', 'time'] as $type) {
            $this->assertSame('string', $this->resolver->resolve($type, false), "Failed for: $type");
        }
    }

    public function test_json_types(): void
    {
        foreach (['json', 'jsonb'] as $type) {
            $this->assertSame('unknown', $this->resolver->resolve($type, false), "Failed for: $type");
        }
    }

    public function test_binary_types(): void
    {
        foreach (['blob', 'binary'] as $type) {
            $this->assertSame('string', $this->resolver->resolve($type, false), "Failed for: $type");
        }
    }

    public function test_uuid_type(): void
    {
        $this->assertSame('string', $this->resolver->resolve('uuid', false));
    }

    public function test_enum_type(): void
    {
        $this->assertSame('string', $this->resolver->resolve('enum', false));
    }

    public function test_unknown_type(): void
    {
        $this->assertSame('unknown', $this->resolver->resolve('geometry', false));
    }

    public function test_nullable_adds_null_union(): void
    {
        $this->assertSame('string | null', $this->resolver->resolve('varchar', true));
        $this->assertSame('number | null', $this->resolver->resolve('integer', true));
        $this->assertSame('boolean | null', $this->resolver->resolve('boolean', true));
    }
}
