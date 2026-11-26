<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * @typefinder-return array{errors: array<string, string>, name: string}
     */
    public function share(Request $request): array
    {
        return [...parent::share($request), 'name' => 'App', 'debug' => true];
    }
}
?>
-----
<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * @typefinder-return array{errors: array<string, string>, name: string, debug: bool}
     */
    public function share(Request $request): array
    {
        return [...parent::share($request), 'name' => 'App', 'debug' => true];
    }
}
?>
