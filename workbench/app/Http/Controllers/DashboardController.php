<?php

namespace App\Http\Controllers;

use Pentacore\Typefinder\Attributes\TypefinderPage;

class DashboardController
{
    #[TypefinderPage('Dashboard', ['pending' => 'number', 'greeting' => 'string'])]
    public function index(): void
    {
        //
    }
}
