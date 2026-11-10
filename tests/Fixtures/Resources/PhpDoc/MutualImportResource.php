<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Fixtures\Resources\PhpDoc\Shapes\MutualShapes;

/**
 * Imports `Remote` from MutualShapes, which in turn imports this class's
 * independent `Local`.
 *
 * @phpstan-import-type Remote from MutualShapes
 *
 * @phpstan-type Local array{n: int}
 */
class MutualImportResource extends JsonResource
{
    /**
     * @return array{remote: Remote}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
