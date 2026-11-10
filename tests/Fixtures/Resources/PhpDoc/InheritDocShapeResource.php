<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Http\Request;
use Tests\Fixtures\Resources\PhpDoc\Concerns\BaseShapeResource;

class InheritDocShapeResource extends BaseShapeResource
{
    /** {@inheritDoc} */
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'extra' => true];
    }
}
