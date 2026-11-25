<?php

declare(strict_types=1);

namespace Tests\Fixtures\Middleware\Undocumented;

use Illuminate\Http\Request;
use Inertia\Middleware;

/** Only parsed, never loaded (Inertia is not installed). */
class NoReturnTagMiddleware extends Middleware
{
    /**
     * A doc with no return tag.
     */
    public function share(Request $request): array
    {
        return ['theme' => 'dark'];
    }
}
