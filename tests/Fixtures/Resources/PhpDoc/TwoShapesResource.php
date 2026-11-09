<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TwoShapesResource extends JsonResource
{
    /**
     * @return array{a: int}|array{b: int}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
