<?php

namespace App\Http\Resources\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Abstract parent in its own namespace: it gets no file of its own. `User`
 * resolves through this file's imports, `static` to the generated child.
 */
abstract class BaseShapeResource extends JsonResource
{
    /**
     * @return array{id: int, owner: User, self: static|null}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
