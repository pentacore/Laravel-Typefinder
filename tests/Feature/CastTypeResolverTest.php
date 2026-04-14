<?php

declare(strict_types=1);

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

final class CastTypeResolverTest extends TestCase
{
    private CastTypeResolver $castTypeResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->castTypeResolver = new CastTypeResolver;
    }

    public function test_built_in_string_casts(): void
    {
        $this->assertSame('string', $this->castTypeResolver->resolve('string'));
        $this->assertSame('string', $this->castTypeResolver->resolve('encrypted'));
        $this->assertSame('string', $this->castTypeResolver->resolve('hashed'));
    }

    public function test_built_in_boolean_cast(): void
    {
        $this->assertSame('boolean', $this->castTypeResolver->resolve('boolean'));
    }

    public function test_built_in_number_casts(): void
    {
        $this->assertSame('number', $this->castTypeResolver->resolve('integer'));
        $this->assertSame('number', $this->castTypeResolver->resolve('int'));
        $this->assertSame('number', $this->castTypeResolver->resolve('float'));
        $this->assertSame('number', $this->castTypeResolver->resolve('real'));
        $this->assertSame('number', $this->castTypeResolver->resolve('double'));
        $this->assertSame('number', $this->castTypeResolver->resolve('decimal'));
    }

    public function test_built_in_date_casts(): void
    {
        $this->assertSame('string', $this->castTypeResolver->resolve('datetime'));
        $this->assertSame('string', $this->castTypeResolver->resolve('date'));
        $this->assertSame('string', $this->castTypeResolver->resolve('timestamp'));
        $this->assertSame('string', $this->castTypeResolver->resolve('immutable_datetime'));
        $this->assertSame('string', $this->castTypeResolver->resolve('immutable_date'));
    }

    public function test_built_in_array_casts(): void
    {
        $this->assertSame('unknown[]', $this->castTypeResolver->resolve('array'));
        $this->assertSame('unknown[]', $this->castTypeResolver->resolve('collection'));
        $this->assertSame('unknown[]', $this->castTypeResolver->resolve('encrypted:array'));
        $this->assertSame('unknown[]', $this->castTypeResolver->resolve('encrypted:collection'));
    }

    public function test_built_in_object_casts(): void
    {
        $this->assertSame('Record<string, unknown>', $this->castTypeResolver->resolve('object'));
        $this->assertSame('Record<string, unknown>', $this->castTypeResolver->resolve('encrypted:object'));
    }

    public function test_json_cast(): void
    {
        $this->assertSame('unknown', $this->castTypeResolver->resolve('json'));
    }

    public function test_laravel_class_casts(): void
    {
        $this->assertSame('Record<string, unknown>', $this->castTypeResolver->resolve(AsArrayObject::class));
        $this->assertSame('unknown[]', $this->castTypeResolver->resolve(AsCollection::class));
        $this->assertSame('string', $this->castTypeResolver->resolve(AsStringable::class));
    }

    public function test_backed_enum_cast(): void
    {
        $result = $this->castTypeResolver->resolve(PostStatus::class);

        $this->assertSame(PostStatus::class, $result['enum']);
    }

    public function test_as_enum_collection_cast(): void
    {
        $result = $this->castTypeResolver->resolve(AsEnumCollection::class.':'.PostTag::class);

        $this->assertSame(PostTag::class, $result['enumCollection']);
    }

    public function test_has_type_definition_cast(): void
    {
        $this->assertSame('{ theme: string; notifications: boolean }', $this->castTypeResolver->resolve(SettingsCast::class));
    }

    public function test_unknown_custom_cast(): void
    {
        $this->assertSame('unknown', $this->castTypeResolver->resolve('SomeUnknown\\CustomCast'));
    }

    public function test_config_overrides(): void
    {
        $castTypeResolver = new CastTypeResolver(['datetime' => 'Date']);

        $this->assertSame('Date', $castTypeResolver->resolve('datetime'));
    }
}
