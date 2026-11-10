<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ListTopLevelResource extends JsonResource
{
    /**
     * @return array{int, string}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
