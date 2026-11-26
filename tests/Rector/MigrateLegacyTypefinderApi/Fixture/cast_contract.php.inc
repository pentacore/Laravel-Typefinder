<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Pentacore\Typefinder\Contracts\HasTypeDefinition;

class SettingsCast implements CastsAttributes, HasTypeDefinition
{
    public static function typeDefinition(): string
    {
        return '{ theme: string }';
    }

    public function get($model, string $key, $value, array $attributes): mixed
    {
        return $value;
    }

    public function set($model, string $key, $value, array $attributes): mixed
    {
        return $value;
    }
}
?>
-----
<?php

namespace App\Casts;

use Pentacore\Typefinder\Attributes\TypefinderCast;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

#[TypefinderCast('{ theme: string }')]
class SettingsCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): mixed
    {
        return $value;
    }

    public function set($model, string $key, $value, array $attributes): mixed
    {
        return $value;
    }
}
?>
