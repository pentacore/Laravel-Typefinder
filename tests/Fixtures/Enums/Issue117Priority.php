<?php

declare(strict_types=1);

namespace Tests\Fixtures\Enums;

enum Issue117Priority: int
{
    case Low = 1;
    case Normal = 2;
    case High = 3;
}
