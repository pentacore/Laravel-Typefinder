<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Casts\SettingsCast;
use Pentacore\Typefinder\Contracts\HasTypeDefinition;
use Tests\TestCase;

final class HasTypeDefinitionTest extends TestCase
{
    public function test_settings_cast_implements_has_type_definition(): void
    {
        $this->assertInstanceOf(HasTypeDefinition::class, new SettingsCast);
    }

    public function test_settings_cast_returns_type_definition(): void
    {
        $this->assertSame('{ theme: string; notifications: boolean }', SettingsCast::typeDefinition());
    }
}
