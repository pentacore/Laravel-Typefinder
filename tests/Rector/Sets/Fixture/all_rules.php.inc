<?php

namespace App\Http\Middleware;

use App\Enums\PostStatus;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * @typefinder-return array{status: PostStatus}
     */
    public function share(Request $request): array
    {
        return [...parent::share($request), 'status' => PostStatus::Draft, 'name' => 'App'];
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
     * @typefinder-return array{status: \App\Enums\PostStatus, name: string}
     */
    public function share(Request $request): array
    {
        return [...parent::share($request), 'status' => PostStatus::Draft, 'name' => 'App'];
    }
}
?>
