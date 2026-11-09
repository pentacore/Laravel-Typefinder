<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Concerns;

use Illuminate\Http\Request;

trait DocumentedToArray
{
    /**
     * @return array{trait_field: string}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
