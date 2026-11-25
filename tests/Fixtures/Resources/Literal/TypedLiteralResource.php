<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\Literal;

use App\Http\Resources\OrphanResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class TypedLiteralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->resource->name,
            'posts' => $this->posts,
            'label' => 'x',
            'count' => 3,
            'flag' => true,
            'nothing' => null,
            'price' => (float) $this->price,
            'author' => new UserResource($this->user),
            'friend' => UserResource::make($this->friend),
            'friends' => UserResource::collection($this->friends),
            'orphan' => new OrphanResource($this->orphan),
            'meta' => ['total' => 1, 'tag' => $this->when($request->has('tag'), fn (): string => 'a')],
            'secret' => $this->when($request->has('secret'), 'yes'),
            'others' => static::collection([]),
            'dynamic' => $this->resourceClass()::make(1),
            'viewer' => $request->user(),
            $this->mergeWhen($request->has('extra'), ['extra' => 1]),
        ];
    }
}
