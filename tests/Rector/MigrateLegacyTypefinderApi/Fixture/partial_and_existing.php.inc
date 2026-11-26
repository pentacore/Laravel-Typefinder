<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;
use Pentacore\Typefinder\Concerns\HasTypeOverrides;
use Pentacore\Typefinder\Concerns\HasWriteShapeContract;

#[TypefinderOverrides(['meta' => 'string'])]
class Tag extends Model
{
    use HasFactory, HasTypeOverrides;
    use HasWriteShapeContract;

    public function typeOverrides(): array
    {
        return ['meta' => 'string'];
    }

    public static function typefinderServerFilled(): array
    {
        return ['slug' . PHP_EOL . strtoupper('x')];
    }

    public static function typefinderImmutableOnUpdate(): array
    {
        $columns = ['slug'];

        return $columns;
    }
}
?>
-----
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

#[TypefinderOverrides(['meta' => 'string'])]
class Tag extends Model
{
    use HasFactory;

    public function typeOverrides(): array
    {
        return ['meta' => 'string'];
    }

    public static function typefinderServerFilled(): array
    {
        return ['slug' . PHP_EOL . strtoupper('x')];
    }

    public static function typefinderImmutableOnUpdate(): array
    {
        $columns = ['slug'];

        return $columns;
    }
}
?>
