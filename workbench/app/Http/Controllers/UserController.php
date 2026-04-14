<?php

namespace App\Http\Controllers;

use App\Models\User;
use Pentacore\Typefinder\Attributes\TypefinderPage;

class UserController
{
    #[TypefinderPage('Users/Show', ['user' => User::class, 'canEdit' => 'boolean'])]
    public function show(): void
    {
        //
    }
}
