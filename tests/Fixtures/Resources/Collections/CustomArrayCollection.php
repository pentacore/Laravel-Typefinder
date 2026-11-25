<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\Collections;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/** Overrides toArray(), so its items are not just the collected resource's. */
class CustomArrayCollection extends ResourceCollection
{
    public $collects = WidgetResource::class;

    public function toArray(Request $request): array
    {
        return ['items' => parent::toArray($request), ...$this->extra()];
    }

    /** @return array<string, mixed> */
    private function extra(): array
    {
        return [];
    }
}
