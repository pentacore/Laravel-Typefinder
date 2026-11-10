<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Pentacore\Typefinder\Attributes\TypefinderPage;

/**
 * Renders that agree with their `#[TypefinderPage]` once repeated attributes
 * and shared props are taken into account: no drift warnings. Inertia is not
 * installed in the workbench: this file is only parsed, its methods never run.
 */
class InSyncPageController
{
    // Two declarations of one component on one action merge before the
    // check: `post` is only passed when editing, so it is optional.
    #[TypefinderPage('InSync/Form', ['title' => 'string'])]
    #[TypefinderPage('InSync/Form', ['title' => 'string', 'post' => 'number'])]
    public function form(?int $post = null): mixed
    {
        if ($post === null) {
            return Inertia::render('InSync/Form', ['title' => 'New post']);
        }

        return Inertia::render('InSync/Form', ['title' => 'Edit post', 'post' => $post]);
    }

    // `errors` is shared by Inertia's middleware, `auth` and `flash` by the
    // app's HandleInertiaRequests::share().
    #[TypefinderPage('InSync/Dashboard', [
        'stats' => 'number',
        'errors' => 'Record<string, string>',
        'auth' => '{ user: unknown }',
        'flash' => '{ message: string | null }',
    ])]
    public function dashboard(): mixed
    {
        return Inertia::render('InSync/Dashboard', ['stats' => 1]);
    }

    // `notice` is shared by this action itself.
    #[TypefinderPage('InSync/Notice', ['body' => 'string', 'notice' => 'string'])]
    public function notice(): mixed
    {
        Inertia::share('notice', 'Saved');

        return Inertia::render('InSync/Notice', ['body' => 'Done']);
    }
}
