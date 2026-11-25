<?php

declare(strict_types=1);

namespace Tests\Fixtures\Middleware\Documented;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Shares props with a documented `share()` (#173). Inertia is not installed:
 * this file is only parsed, never loaded.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * @return array{auth: array{user: User|null}, flash: array{message: string|null}, ziggy?: array<string, mixed>, version: string}
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => ['user' => $request->user()],
            'flash' => fn (): array => ['message' => $request->session()->get('message')],
            'ziggy' => $this->when($request->has('ziggy'), fn (): array => []),
            'undocumented' => 1,
        ];
    }
}
