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
class PlainArrayResource extends JsonResource
{
    /**
     * @return array|Arrayable|JsonSerializable the parent's return type, not narrowed
     */
    public function toArray(Request $request): array|Arrayable|JsonSerializable
    {
        return [];
    }
}
