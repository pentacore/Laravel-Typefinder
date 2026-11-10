<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition;

use Illuminate\Http\Request;
use Tests\Fixtures\Resources\PhpDoc\Composition\Concerns\ComposingBaseResource;

/**
 * The abstract parent's undocumented toArray() composes; `author()` is
 * declared in the parent. Neither `PostStatus` nor `User` is imported here.
 */
class ParentHelperResource extends ComposingBaseResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), $this->author());
    }
}
