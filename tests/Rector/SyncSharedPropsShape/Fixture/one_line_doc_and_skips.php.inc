<?php

namespace App\Http\Middleware;

use App\Enums\PostStatus;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /** Shared props. */
    public function share(Request $request): array
    {
        return [...parent::share($request), 'status' => PostStatus::Draft, 'locales' => ['en', 'sv']];
    }
}

class OnlyParent extends Middleware
{
    public function share(Request $request): array
    {
        return parent::share($request);
    }
}

class NoShare extends Middleware
{
}

class NotInertia
{
    public function share(Request $request): array
    {
        return ['a' => 1];
    }
}
?>
-----
<?php

namespace App\Http\Middleware;

use App\Enums\PostStatus;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * Shared props.
     *
     * @typefinder-return array{
     *     status: \App\Enums\PostStatus,
     *     locales: list<string>,
     * }
     */
    public function share(Request $request): array
    {
        return [...parent::share($request), 'status' => PostStatus::Draft, 'locales' => ['en', 'sv']];
    }
}

class OnlyParent extends Middleware
{
    public function share(Request $request): array
    {
        return parent::share($request);
    }
}

class NoShare extends Middleware
{
}

class NotInertia
{
    public function share(Request $request): array
    {
        return ['a' => 1];
    }
}
?>
