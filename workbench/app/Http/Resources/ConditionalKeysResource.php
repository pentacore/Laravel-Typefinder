<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Key drift against a PHPDoc shape: `posts` and `meta` are declared required
 * but only ever returned conditionally, `name` is missing from the summary
 * return, and `stats` is returned but not declared. `extra` is optional and
 * always returned, which is harmless.
 */
class ConditionalKeysResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, posts: list<PostResource>, meta: array{total: int}, extra?: string}
     */
    public function toArray(Request $request): array
    {
        if ($request->boolean('summary')) {
            return [
                'id' => $this->id,
                'posts' => PostResource::collection($this->whenLoaded('posts')),
                $this->mergeWhen($request->boolean('detailed'), [
                    'meta' => ['total' => $this->posts_count],
                ]),
            ];
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'posts' => PostResource::collection($this->whenLoaded('posts')),
            'extra' => $this->extra,
            $this->mergeWhen($request->boolean('detailed'), [
                'meta' => ['total' => $this->posts_count],
                'stats' => ['views' => $this->views],
            ]),
        ];
    }
}
