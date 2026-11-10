<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the Comment model by name, but the documented shape on toArray()
 * wins over the name convention.
 */
class CommentResource extends JsonResource
{
    /**
     * @return array{id: int, body: non-empty-string, author: UserResource, post_id: int, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'author' => new UserResource($this->user),
            'post_id' => $this->post_id,
            'created_at' => $this->created_at?->toJSON(),
        ];
    }
}
