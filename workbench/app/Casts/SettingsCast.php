<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Pentacore\Typefinder\Contracts\HasTypeDefinition;

class SettingsCast implements CastsAttributes, HasTypeDefinition
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return json_decode($value ?? '{}', true);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return json_encode($value);
    }

    public static function typeDefinition(): string
    {
        return '{ theme: string; notifications: boolean }';
    }
}
