<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Abstract parent in its own namespace: `User` resolves through this file's
 * imports, `static` to whichever child is generated.
 */
abstract class BaseShapeResource extends JsonResource
{
    /**
     * @return array{id: int, owner: User, self: static}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
