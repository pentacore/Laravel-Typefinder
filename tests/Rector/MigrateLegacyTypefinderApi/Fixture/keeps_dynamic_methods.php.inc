<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Pentacore\Typefinder\Concerns\HasTypeOverrides;

class Invoice extends Model
{
    use HasTypeOverrides;

    public function typeOverrides(): array
    {
        return config('overrides');
    }
}
?>
-----
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    public function typeOverrides(): array
    {
        return config('overrides');
    }
}
?>
