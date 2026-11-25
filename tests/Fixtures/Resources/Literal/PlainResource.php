<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\Literal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \stdClass not a model, so `$this->id` stays unknown
 */
class PlainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'posts' => $this->whenLoaded('posts')];
    }
}
