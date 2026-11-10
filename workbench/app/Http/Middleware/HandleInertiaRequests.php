<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * The app's Inertia middleware, as `php artisan inertia:middleware` writes
 * it. Drift checks read the keys `share()` returns as props every page gets.
 * Inertia is not installed in the workbench: this file is only parsed, never
 * loaded.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => ['user' => $request->user()],
            'flash' => fn (): array => ['message' => $request->session()->get('message')],
        ]);
    }
}
