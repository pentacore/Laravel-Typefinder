<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrecedenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     *
     * @phpstan-return array{id: int, name: string}
     *
     * @psalm-return array{psalm: int}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
