<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Concerns;

use App\Models\User as Owner;
use Illuminate\Http\Request;

/**
 * `Owner` is imported here only, so it must resolve in this file, and the
 * `Meta` alias comes from this trait's own doc.
 *
 * @phpstan-type Meta array{count: int}
 */
trait AliasedOwnerToArray
{
    /**
     * @return array{owner: Owner, meta: Meta}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
