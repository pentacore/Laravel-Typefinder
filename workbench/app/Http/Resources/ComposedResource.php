<?php

namespace App\Http\Resources;

use App\Enums\PostStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * No @return on toArray(): the shape is composed from the documented helpers
 * it spreads.
 */
class ComposedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->base(), ...$this->counts()];
    }

    /**
     * @return array{id: int, title: string, status: PostStatus}
     */
    private function base(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
        ];
    }

    /**
     * @return array{comments_count: int, author?: UserResource}
     */
    private function counts(): array
    {
        return [
            'comments_count' => $this->comments_count,
            'author' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
