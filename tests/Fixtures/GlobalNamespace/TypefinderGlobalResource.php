<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Global namespace on purpose: PSR-4 cannot load it, tests `require_once` it.
 */
class TypefinderGlobalResource extends JsonResource
{
    /**
     * @return array{owner: TypefinderGlobalModel, status: TypefinderGlobalStatus, item: TypefinderGlobalItemResource, self: static}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
