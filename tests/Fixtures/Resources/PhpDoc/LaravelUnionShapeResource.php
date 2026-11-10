<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonSerializable;

/**
 * Laravel's own toArray() signature.
 */
class LaravelUnionShapeResource extends JsonResource
{
    /**
     * @return array{id: int}|Arrayable|JsonSerializable
     */
    public function toArray(Request $request): array|Arrayable|JsonSerializable
    {
        return [];
    }
}
