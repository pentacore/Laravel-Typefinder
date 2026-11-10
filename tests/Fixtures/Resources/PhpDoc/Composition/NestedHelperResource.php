<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Depth 2: an undocumented helper whose own body delegates; its literal key
 * is undocumented too.
 */
class NestedHelperResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->summary();
    }

    private function summary(): array
    {
        return [...$this->ids(), 'label' => $this->label];
    }

    /**
     * @return array{id: int}
     */
    private function ids(): array
    {
        return ['id' => $this->id];
    }
}
