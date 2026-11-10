<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ignored\Enums;

use Pentacore\Typefinder\Attributes\TypefinderIgnore;

#[TypefinderIgnore]
enum IgnoredStatus: string
{
    case On = 'on';
    case Off = 'off';
}
