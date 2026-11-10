<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Fixtures\Resources\PhpDoc\Shapes\ManyImportShapes;

/**
 * Defines 6 aliases and imports all 6 of ManyImportShapes', which imports
 * all of these back: resolving one alias must not build every other one.
 *
 * @phpstan-import-type BT1 from ManyImportShapes
 * @phpstan-import-type BT2 from ManyImportShapes
 * @phpstan-import-type BT3 from ManyImportShapes
 * @phpstan-import-type BT4 from ManyImportShapes
 * @phpstan-import-type BT5 from ManyImportShapes
 * @phpstan-import-type BT6 from ManyImportShapes
 *
 * @phpstan-type AT1 array{a1: int}
 * @phpstan-type AT2 array{a2: int}
 * @phpstan-type AT3 array{a3: int}
 * @phpstan-type AT4 array{a4: int}
 * @phpstan-type AT5 array{a5: int}
 * @phpstan-type AT6 array{a6: int}
 */
class ManyImportsResource extends JsonResource
{
    /**
     * @return array{x: BT1}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
