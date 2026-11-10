<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\BaseShapeResource;
use Illuminate\Http\Request;

/**
 * Composed from the documented parent toArray() (abstract, in another
 * namespace: `User` resolves through the parent's imports, `static` to this
 * class) and a documented helper.
 */
class ComposedParentResource extends BaseShapeResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), $this->extra());
    }

    /**
     * @return array{tags: list<string>, featured?: bool}
     */
    protected function extra(): array
    {
        return ['tags' => $this->tags, 'featured' => $this->featured];
    }
}
