<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class ComposingGrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->grand()];
    }

    /**
     * @return array{grand: int}
     */
    protected function grand(): array
    {
        return ['grand' => 1];
    }
}
