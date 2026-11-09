<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PsalmReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     *
     * @psalm-return array{id: int}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
