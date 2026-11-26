<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Pentacore\Typefinder\Concerns\HasTypeOverrides;
use Pentacore\Typefinder\Concerns\HasWriteShapeContract;

class Post extends Model
{
    use HasTypeOverrides;
    use HasWriteShapeContract;

    public function typeOverrides(): array
    {
        return ['meta' => '{ seo: string }', 'owner' => User::class];
    }

    public static function typefinderServerFilled(): array
    {
        return ['slug'];
    }

    public static function typefinderRespectMassAssignment(): ?bool
    {
        return false;
    }

    public function title(): string
    {
        return 'x';
    }
}
?>
-----
<?php

namespace App\Models;

use Pentacore\Typefinder\Attributes\TypefinderOverrides;
use Pentacore\Typefinder\Attributes\TypefinderWriteShape;
use Illuminate\Database\Eloquent\Model;

#[TypefinderOverrides(['meta' => '{ seo: string }', 'owner' => User::class])]
#[TypefinderWriteShape(serverFilled: ['slug'], respectMassAssignment: false)]
class Post extends Model
{
    public function title(): string
    {
        return 'x';
    }
}
?>
