<?php

declare(strict_types=1);

namespace Tests\Fixtures\Broadcasting;

enum EventName: string
{
    case Created = 'thing.created';
}
