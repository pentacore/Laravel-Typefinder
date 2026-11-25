<?php

declare(strict_types=1);

namespace Tests\Fixtures\Controllers;

use Inertia\Inertia;
use Pentacore\Typefinder\Attributes\TypefinderPage;

/**
 * A method chained on a deferred or optional prop leaves it deferred or
 * optional (#174). Only parsed, never run.
 */
class ChainedPropController
{
    #[TypefinderPage('Drift/Chained', ['stats' => 'number', 'feed' => 'string[]'])]
    public function chained(): mixed
    {
        return Inertia::render('Drift/Chained', [
            'stats' => Inertia::defer(fn (): int => 1)->merge(),
            'feed' => Inertia::optional(fn (): array => [])->once(),
        ]);
    }
}
