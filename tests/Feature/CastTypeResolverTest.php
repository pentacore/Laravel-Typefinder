<?php

namespace Tests\Feature;

use App\Casts\SettingsCast;
use App\Enums\PostStatus;
use App\Enums\PostTag;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Tests\TestCase;

class CastTypeResolverTest extends TestCase
{
    private CastTypeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new CastTypeResolver;
    }

    public function test_built_in_string_casts(): void
    {
        $this->assertSame('string', $this->resolver->resolve('string'));
        $this->assertSame('string', $this->resolver->resolve('encrypted'));
        $this->assertSame('string', $this->resolver->resolve('hashed'));
    }

    public function test_built_in_boolean_cast(): void
    {
        $this->assertSame('boolean', $this->resolver->resolve('boolean'));
    }

    public function test_built_in_number_casts(): void
    {
        $this->assertSame('number', $this->resolver->resolve('integer'));
        $this->assertSame('number', $this->resolver->resolve('int'));
        $this->assertSame('number', $this->resolver->resolve('float'));
        $this->assertSame('number', $this->resolver->resolve('real'));
        $this->assertSame('number', $this->resolver->resolve('double'));
        $this->assertSame('number', $this->resolver->resolve('decimal'));
    }

    public function test_built_in_date_casts(): void
    {
        $this->assertSame('string', $this->resolver->resolve('datetime'));
        $this->assertSame('string', $this->resolver->resolve('date'));
        $this->assertSame('string', $this->resolver->resolve('timestamp'));
        $this->assertSame('string', $this->resolver->resolve('immutable_datetime'));
        $this->assertSame('string', $this->resolver->resolve('immutable_date'));
    }

    public function test_built_in_array_casts(): void
    {
        $this->assertSame('unknown[]', $this->resolver->resolve('array'));
        $this->assertSame('unknown[]', $this->resolver->resolve('collection'));
        $this->assertSame('unknown[]', $this->resolver->resolve('encrypted:array'));
        $this->assertSame('unknown[]', $this->resolver->resolve('encrypted:collection'));
    }

    public function test_built_in_object_casts(): void
    {
        $this->assertSame('Record<string, unknown>', $this->resolver->resolve('object'));
        $this->assertSame('Record<string, unknown>', $this->resolver->resolve('encrypted:object'));
    }

    public function test_json_cast(): void
    {
        $this->assertSame('unknown', $this->resolver->resolve('json'));
    }

    public function test_laravel_class_casts(): void
    {
        $this->assertSame('Record<string, unknown>', $this->resolver->resolve(AsArrayObject::class));
        $this->assertSame('unknown[]', $this->resolver->resolve(AsCollection::class));
        $this->assertSame('string', $this->resolver->resolve(AsStringable::class));
    }

    public function test_backed_enum_cast(): void
    {
        $result = $this->resolver->resolve(PostStatus::class);

        $this->assertSame(PostStatus::class, $result['enum']);
    }

    public function test_as_enum_collection_cast(): void
    {
        $result = $this->resolver->resolve(AsEnumCollection::class.':'.PostTag::class);

        $this->assertSame(PostTag::class, $result['enumCollection']);
    }

    public function test_has_type_definition_cast(): void
    {
        $this->assertSame('{ theme: string; notifications: boolean }', $this->resolver->resolve(SettingsCast::class));
    }

    public function test_unknown_custom_cast(): void
    {
        $this->assertSame('unknown', $this->resolver->resolve('SomeUnknown\\CustomCast'));
    }

    public function test_config_overrides(): void
    {
        $resolver = new CastTypeResolver(['datetime' => 'Date']);

        $this->assertSame('Date', $resolver->resolve('datetime'));
    }
}
