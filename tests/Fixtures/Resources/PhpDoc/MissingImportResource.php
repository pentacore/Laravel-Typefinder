<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Fixtures\Resources\PhpDoc\Shapes\SharedShapes;

/**
 * @psalm-import-type Nope from SharedShapes
 * @psalm-import-type Address from NoSuchShapes
 */
class MissingImportResource extends JsonResource
{
    /**
     * @return array{a: Nope, b: Address}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
