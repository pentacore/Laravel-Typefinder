<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Support;

use Throwable;

/**
 * The text to report for a throwable. HTTP exceptions from `abort()` carry
 * no message by default, so an empty message is replaced by the exception's
 * class instead of leaving the report blank.
 */
final class ThrowableMessage
{
    public static function describe(Throwable $throwable): string
    {
        $message = trim($throwable->getMessage());

        return $message === '' ? $throwable::class.' (no message)' : $message;
    }
}
