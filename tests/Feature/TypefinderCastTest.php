<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Casts\SettingsCast;
use Pentacore\Typefinder\Attributes\TypefinderCast;
use Pentacore\Typefinder\Facades\Typefinder;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\TypefinderRegistry;
use ReflectionClass;
use Tests\TestCase;

final class TypefinderCastTest extends TestCase
{
    public function test_settings_cast_declares_type_via_attribute(): void
    {
        $attrs = (new ReflectionClass(SettingsCast::class))->getAttributes(TypefinderCast::class);

        $this->assertCount(1, $attrs);
        $this->assertSame('{ theme: string; notifications: boolean }', $attrs[0]->newInstance()->type);
    }

    public function test_resolver_reads_type_from_attribute(): void
    {
        $castTypeResolver = new CastTypeResolver;

        $this->assertSame(
            '{ theme: string; notifications: boolean }',
            $castTypeResolver->resolve(SettingsCast::class),
        );
    }

    public function test_registry_registrations_win_over_attribute(): void
    {
        $typefinderRegistry = app(TypefinderRegistry::class);
        $typefinderRegistry->registerCast(SettingsCast::class, '{ override: true }');

        $castTypeResolver = new CastTypeResolver([], $typefinderRegistry);

        $this->assertSame('{ override: true }', $castTypeResolver->resolve(SettingsCast::class));
    }

    public function test_facade_registers_closure_casts(): void
    {
        Typefinder::registerCast('App\\Fake\\DynamicCast', fn (): string => 'string | number');

        $castTypeResolver = new CastTypeResolver([], app(TypefinderRegistry::class));

        $this->assertSame('string | number', $castTypeResolver->resolve('App\\Fake\\DynamicCast'));
    }

    public function test_config_overrides_still_win_over_attribute(): void
    {
        $castTypeResolver = new CastTypeResolver([SettingsCast::class => '{ cfg: true }']);

        $this->assertSame('{ cfg: true }', $castTypeResolver->resolve(SettingsCast::class));
    }

    public function test_unknown_cast_returns_unknown(): void
    {
        $castTypeResolver = new CastTypeResolver;

        $this->assertSame('unknown', $castTypeResolver->resolve('App\\Nope\\NotACast'));
    }
}
