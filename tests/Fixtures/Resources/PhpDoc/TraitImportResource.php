<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Fixtures\Resources\PhpDoc\Shapes\MoneyShapes;

/**
 * @phpstan-import-type Money from MoneyShapes
 */
class TraitImportResource extends JsonResource
{
    /**
     * @return array{price: Money}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
