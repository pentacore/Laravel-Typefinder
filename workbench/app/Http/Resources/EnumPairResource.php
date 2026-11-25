<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\PostStatus;
use App\Enums\PostTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderResource;

/**
 * Two enums from the same module: one merged import line (#214). The
 * generated output is linted in CI, which rejects duplicate imports.
 */
#[TypefinderResource(shape: [
    'status' => PostStatus::class,
    'tag' => PostTag::class,
])]
class EnumPairResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status,
            'tag' => $this->tag,
        ];
    }
}
