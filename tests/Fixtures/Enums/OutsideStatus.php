<?php

declare(strict_types=1);

namespace Tests\Fixtures\Enums;

enum OutsideStatus: string
{
    case On = 'on';
    case Off = 'off';
}
