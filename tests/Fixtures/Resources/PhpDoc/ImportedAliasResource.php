<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Fixtures\Resources\PhpDoc\Shapes\SharedShapes;

/**
 * @phpstan-import-type Address from SharedShapes as Addr
 * @phpstan-import-type Contact from SharedShapes
 */
class ImportedAliasResource extends JsonResource
{
    /**
     * @return array{address: Addr, contact: Contact}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
