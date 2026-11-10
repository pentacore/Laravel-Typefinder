<?php

namespace App\Http\Controllers;

use App\Models\User;
use Inertia\Inertia;
use Pentacore\Typefinder\Attributes\TypefinderPage;

/**
 * Prop drift between `#[TypefinderPage]` and the `Inertia::render()` call of
 * the same action. Inertia is not installed in the workbench: this file is
 * only parsed, its methods never run.
 */
class DriftingPageController
{
    // Passes `c` (undeclared), never passes the declared `b`.
    #[TypefinderPage('Drift/Show', ['a' => 'string', 'b' => 'number'])]
    public function show(): mixed
    {
        return Inertia::render('Drift/Show', [
            'a' => 'hello',
            'c' => Inertia::optional(fn (): int => 1),
        ]);
    }

    // `compact()` passes `roles`, which is not declared.
    #[TypefinderPage('Drift/Edit', ['user' => User::class])]
    public function edit(User $user): mixed
    {
        $roles = ['admin'];

        return Inertia::render('Drift/Edit', compact('user', 'roles'));
    }

    // `stats` is deferred, so it is missing on first load, but declared required.
    #[TypefinderPage('Drift/Index', ['items' => 'string[]', 'stats' => '{ total: number }'])]
    public function index(): mixed
    {
        return Inertia::render('Drift/Index', [
            'items' => [],
            'stats' => Inertia::defer(fn (): array => ['total' => 0]),
        ]);
    }

    // The render lives in a helper, so there is nothing to compare.
    #[TypefinderPage('Drift/Helper', ['a' => 'string'])]
    public function helper(): mixed
    {
        return $this->renderHelper();
    }

    private function renderHelper(): mixed
    {
        return Inertia::render('Drift/Helper', ['a' => 'hello', 'z' => 1]);
    }
}
