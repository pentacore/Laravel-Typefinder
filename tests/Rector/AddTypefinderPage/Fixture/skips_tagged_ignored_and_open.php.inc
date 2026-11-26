<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Attributes\TypefinderPage;

final class SkippedController
{
    #[TypefinderPage(component: 'Tagged', props: ['a' => 'string'])]
    public function tagged(): mixed
    {
        return Inertia::render('Tagged', ['a' => 'x']);
    }

    #[TypefinderIgnore]
    public function ignored(): mixed
    {
        return Inertia::render('Ignored', ['a' => 'x']);
    }

    public function open(array $props): mixed
    {
        return Inertia::render('Open', $props);
    }

    public function assigned(): mixed
    {
        $response = Inertia::render('Assigned', ['a' => 'x']);

        return $response;
    }
}
