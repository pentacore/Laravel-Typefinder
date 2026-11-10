<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition;

use Illuminate\Http\Request;
use Tests\Fixtures\Resources\PhpDoc\Composition\Concerns\PrivateBaseResource;

/**
 * Parent and child each have a private `base()`: PHP returns
 * `['id' => 1, 'name' => 'n']`.
 */
class PrivateCollisionResource extends PrivateBaseResource
{
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), ...$this->base()];
    }

    /**
     * @return array{name: string}
     */
    private function base(): array
    {
        return ['name' => 'n'];
    }
}
