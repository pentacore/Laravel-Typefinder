<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * Define the props that are shared by default.
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => 'App',
            'auth' => ['user' => $request->user()],
        ];
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
     * Define the props that are shared by default.
     *
     * @typefinder-return array{
     *     name: string,
     *     auth: mixed,
     * }
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => 'App',
            'auth' => ['user' => $request->user()],
        ];
    }
}
?>
