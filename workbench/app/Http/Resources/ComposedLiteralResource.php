<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A documented helper plus one literal key: `note` has no documented type, so
 * it is `unknown` and the run warns once.
 */
class ComposedLiteralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->base(), 'note' => $this->note];
    }

    /**
     * @return array{id: int, title: string}
     */
    private function base(): array
    {
        return ['id' => $this->id, 'title' => $this->title];
    }
}
