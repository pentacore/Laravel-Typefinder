<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Fixtures\Resources\PhpDoc\Shapes\CycleShapes;

/**
 * `Loop` is imported from CycleShapes, which imports it back from here.
 *
 * @phpstan-import-type Loop from CycleShapes
 */
class ImportCycleResource extends JsonResource
{
    /**
     * @return array{loop: Loop}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
