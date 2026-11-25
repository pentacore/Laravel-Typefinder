<?php

declare(strict_types=1);

namespace Tests\Fixtures\SnakeRelations;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Returns a relation under its snake-cased key, as `toArray()` serialises it (#220). */
class AuthorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'blog_posts' => $this->whenLoaded('blogPosts'),
        ];
    }
}
