<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;

final class AuthorResource extends JsonResource
{
    /**
     * @typefinder-return array{author: \App\Models\User, sibling: PostResource, n: int}
     * @return array{author: User}
     */
    public function toArray($request): array
    {
        return ['author' => $this->resource];
    }
}
