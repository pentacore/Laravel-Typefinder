<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Concerns;

/**
 * Provides `toArray()` only through the trait it uses.
 */
trait NestedDocumentedToArray
{
    use DocumentedToArray;
}
