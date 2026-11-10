<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Fixtures\Resources\PhpDoc\Shapes\RecursiveShapes;

/**
 * `Branch` (imported) and `Leaf` (local) refer to each other across files.
 *
 * @phpstan-import-type Branch from RecursiveShapes
 *
 * @phpstan-type Leaf array{branch: Branch}
 */
class RecursiveImportResource extends JsonResource
{
    /**
     * @return array{root: Branch}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
