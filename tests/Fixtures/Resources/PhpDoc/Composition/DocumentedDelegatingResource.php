<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A @return shape on toArray() wins over composing its body.
 */
class DocumentedDelegatingResource extends JsonResource
{
    /**
     * @return array{declared: string}
     */
    public function toArray(Request $request): array
    {
        return [...$this->base()];
    }

    /**
     * @return array{id: int}
     */
    private function base(): array
    {
        return ['id' => $this->id];
    }
}
