<?php

declare(strict_types=1);

namespace Tests\Fixtures\Middleware\TypefinderReturn;

use App\Enums\PostStatus;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * `@typefinder-return` declares the serialised shared props, which the
 * analysers do not check against the body (#237), and keeps narrowed keys
 * (#236). Inertia is not installed: this file is only parsed, never loaded.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * Shared with every page.
     *
     * @typefinder-return array{
     *     name: string,
     *     nav: array{unread: int},
     *     can: array<value-of<PostStatus>, bool>,
     *     modes: array<'light'|'dark', string>,
     * }
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'nav' => fn (): array => ['unread' => 3],
            'can' => [],
            'modes' => [],
        ];
    }
}
