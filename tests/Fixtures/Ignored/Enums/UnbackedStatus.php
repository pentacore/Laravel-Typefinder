<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ignored\Enums;

/** Not backed: has no values to emit, so a run skips it. */
enum UnbackedStatus
{
    case On;
    case Off;
}
