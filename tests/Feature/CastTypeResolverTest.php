<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Casts\SettingsCast;
use App\Enums\PostStatus;
use App\Enums\PostTag;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Casts\AsEnumArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\AsFluent;
use Illuminate\Database\Eloquent\Casts\AsHtmlString;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Casts\AsUri;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\TypefinderRegistry;
use Tests\Fixtures\Casts\MoneyCast;
use Tests\Fixtures\Casts\UnmappedCast;
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

    public function test_typefinder_cast_attribute(): void
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

    public function test_additional_built_in_casts(): void
    {
        $this->assertSame('boolean', $this->castTypeResolver->resolve('bool'));
        $this->assertSame('string', $this->castTypeResolver->resolve('custom_datetime'));
        $this->assertSame('string', $this->castTypeResolver->resolve('immutable_custom_datetime'));
        $this->assertSame('unknown', $this->castTypeResolver->resolveOrNull('encrypted:json'));
    }

    public function test_parameterised_built_in_casts_resolve_by_base_name(): void
    {
        $this->assertSame('number', $this->castTypeResolver->resolveOrNull('decimal:2'));
        $this->assertSame('string', $this->castTypeResolver->resolveOrNull('datetime:Y-m-d'));
        $this->assertSame('string', $this->castTypeResolver->resolveOrNull('date:Y-m-d'));
        $this->assertSame('string', $this->castTypeResolver->resolveOrNull('immutable_datetime:Y-m-d H:i'));
        $this->assertSame('string', $this->castTypeResolver->resolveOrNull('immutable_date:Y-m-d'));
        $this->assertSame('unknown', $this->castTypeResolver->resolveOrNull('json:unicode'));
    }

    public function test_exact_parameterised_keys_win_over_base_name(): void
    {
        $this->assertSame('unknown[]', $this->castTypeResolver->resolveOrNull('encrypted:array'));
        $this->assertSame('Record<string, unknown>', $this->castTypeResolver->resolveOrNull('encrypted:object'));
        $this->assertSame('unknown', $this->castTypeResolver->resolveOrNull('encrypted:json'));
    }

    public function test_additional_laravel_class_casts(): void
    {
        $this->assertSame('unknown[]', $this->castTypeResolver->resolveOrNull(AsEncryptedCollection::class));
        $this->assertSame('Record<string, unknown>', $this->castTypeResolver->resolveOrNull(AsEncryptedArrayObject::class));
        $this->assertSame('Record<string, unknown>', $this->castTypeResolver->resolveOrNull(AsFluent::class));
        $this->assertSame('string', $this->castTypeResolver->resolveOrNull(AsUri::class));
        $this->assertSame('string', $this->castTypeResolver->resolveOrNull(AsHtmlString::class));
    }

    public function test_parameterised_laravel_class_casts(): void
    {
        $this->assertSame('unknown[]', $this->castTypeResolver->resolveOrNull(AsCollection::using(SettingsCast::class)));
        $this->assertSame('unknown[]', $this->castTypeResolver->resolveOrNull(AsCollection::of(SettingsCast::class)));
        $this->assertSame('unknown[]', $this->castTypeResolver->resolveOrNull(AsEncryptedCollection::using(SettingsCast::class)));
    }

    public function test_as_enum_array_object_cast(): void
    {
        $this->assertSame(['enumCollection' => PostTag::class], $this->castTypeResolver->resolveOrNull(AsEnumArrayObject::of(PostTag::class)));
    }

    public function test_custom_cast_with_arguments_uses_typefinder_cast_attribute(): void
    {
        $this->assertSame('{ amount: number; currency: string }', $this->castTypeResolver->resolveOrNull(MoneyCast::class.':EUR'));
    }

    public function test_custom_cast_with_arguments_uses_config_and_registry_by_base_class(): void
    {
        $castTypeResolver = new CastTypeResolver([UnmappedCast::class => 'Reward[]']);
        $this->assertSame('Reward[]', $castTypeResolver->resolveOrNull(UnmappedCast::class.':gold,silver'));

        $registry = new TypefinderRegistry;
        $registry->registerCast(UnmappedCast::class, 'Loot');

        $castTypeResolver = new CastTypeResolver([UnmappedCast::class => 'Reward[]'], $registry);
        $this->assertSame('Loot', $castTypeResolver->resolveOrNull(UnmappedCast::class.':gold'));
    }

    public function test_config_override_for_base_name_applies_to_parameterised_cast(): void
    {
        $castTypeResolver = new CastTypeResolver(['datetime' => 'Date']);

        $this->assertSame('Date', $castTypeResolver->resolveOrNull('datetime:Y-m-d'));
    }

    public function test_exact_parameterised_mapping_wins_over_base_mapping(): void
    {
        $castTypeResolver = new CastTypeResolver([
            UnmappedCast::class => 'Reward[]',
            UnmappedCast::class.':gold' => 'GoldReward[]',
        ]);

        $this->assertSame('GoldReward[]', $castTypeResolver->resolveOrNull(UnmappedCast::class.':gold'));
    }

    public function test_unmapped_parameterised_casts_return_null(): void
    {
        $this->assertNull($this->castTypeResolver->resolveOrNull(UnmappedCast::class.':gold'));
        $this->assertNull($this->castTypeResolver->resolveOrNull('SomeUnknown\\CustomCast:arg'));
        $this->assertNull($this->castTypeResolver->resolveOrNull('nonsense:arg'));
    }
}
