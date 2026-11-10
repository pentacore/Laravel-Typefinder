<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Precision;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Case 6: no collisions; both mergeWhen() branches are optional, the
 * literal default branch included.
 */
class MergeWhenBranchesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => 1, $this->mergeWhen($request->boolean('flag'), $this->admin(), ['guest' => true])];
    }

    /**
     * @return array{secret: string}
     */
    private function admin(): array
    {
        return ['secret' => 's'];
    }
}
