<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** `@typefinder-return` beats every analyser return tag (#237). */
class TypefinderReturnResource extends JsonResource
{
    /**
     * @phpstan-return array{id: int, name: string}
     *
     * @typefinder-return array{
     *     slug: string,
     * }
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
