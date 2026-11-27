<?php

declare(strict_types=1);

namespace Tests\Fixtures\Controllers;

use Pentacore\Typefinder\Attributes\TypefinderPage;

/** A prop names a class whose autoloader throws (#247). */
class ThrowingAutoloadPropController
{
    #[TypefinderPage('PhpDoc/Throwing', ['things' => 'list<Tests\\Broken\\Thing>'])]
    public function show(): void {}
}
