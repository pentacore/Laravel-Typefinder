<?php

namespace App\Http\Resources;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderResource;

/**
 * Class-strings for a model, an enum and a resource in a shape. They must be
 * imported relative to resources/ (#75).
 */
#[TypefinderResource(shape: [
    'post' => Post::class,
    'status' => PostStatus::class,
    'author' => UserResource::class.' | null',
    'related' => PostResource::class.'[]',
])]
class PostSummaryResource extends JsonResource {}
