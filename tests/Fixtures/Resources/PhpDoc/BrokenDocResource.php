<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrokenDocResource extends JsonResource
{
    /**
     * @return array{id: int,
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
