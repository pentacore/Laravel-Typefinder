<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition;

use Illuminate\Http\Request;
use Tests\Fixtures\Resources\PhpDoc\Composition\Concerns\MidResource;

class SkipLevelParentResource extends MidResource
{
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), ...$this->own()];
    }

    /**
     * @return array{own: string}
     */
    private function own(): array
    {
        return ['own' => 'o'];
    }
}
