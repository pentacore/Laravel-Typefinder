<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

/**
 * A pure (non-backed) enum: json_encode() cannot serialise it.
 */
enum PureStatus
{
    case On;
    case Off;
}
