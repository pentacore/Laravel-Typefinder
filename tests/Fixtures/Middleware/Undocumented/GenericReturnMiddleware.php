<?php

declare(strict_types=1);

namespace Tests\Fixtures\Middleware\Undocumented;

use Illuminate\Http\Request;
use Inertia\Middleware;

/** Only parsed, never loaded (Inertia is not installed). */
class GenericReturnMiddleware extends Middleware
{
    /**
     * A generic return is no shape, so its keys stay unknown.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return ['locale' => 'en'];
    }
}
