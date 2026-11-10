<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @phpstan-type Meta array{total: int}
 *
 * @psalm-type Count = int
 */
class AliasedShapeResource extends JsonResource
{
    /**
     * @return array{meta: Meta, count: Count}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
