<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'locale' => fn () => 'en',
        ]);
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
     * @typefinder-return array{
     *     locale: string,
     * }
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'locale' => fn () => 'en',
        ]);
    }
}
?>
