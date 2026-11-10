<?php

namespace App\Http\Resources;

use App\Enums\PostStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One field per PHPDoc → TypeScript mapping rule.
 */
class ShapeShowcaseResource extends JsonResource
{
    /**
     * @return array{
     *     id: int,
     *     meta: array{total: int, tags: list<string>},
     *     comments: list<CommentResource>,
     *     scores: array<string, float>,
     *     status: PostStatus,
     *     editor: ?User,
     *     posts: PostResource[]|MissingValue,
     *     pair: array{int, string},
     *     stage: 'draft'|'published',
     *     replies: Collection<int, CommentResource>,
     *     published_at: Carbon,
     *     extra: array{source: string, ...},
     *     'foo-bar': bool,
     * }
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
