<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Concerns\PrivateTraitHelper;

class PrivateTraitResource extends JsonResource
{
    use PrivateTraitHelper;

    public function toArray(Request $request): array
    {
        return $this->traitPart($request);
    }
}
