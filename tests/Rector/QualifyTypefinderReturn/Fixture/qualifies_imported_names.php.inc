<?php

namespace App\Http\Middleware;

use App\Enums\PostStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * Shared props.
     *
     * @typefinder-return array{
     *     user: User|null,
     *     can: array<value-of<PostStatus>, bool>,
     *     status: PostStatus::Draft,
     * }
     */
    public function share(Request $request): array
    {
        return [...parent::share($request), 'user' => $request->user()];
    }
}
?>
-----
<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * Shared props.
     *
     * @typefinder-return array{
     *     user: \App\Models\User|null,
     *     can: array<value-of<\App\Enums\PostStatus>, bool>,
     *     status: \App\Enums\PostStatus::Draft,
     * }
     */
    public function share(Request $request): array
    {
        return [...parent::share($request), 'user' => $request->user()];
    }
}
?>
