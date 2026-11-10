<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderResource;

/**
 * The model attribute is an explicit declaration: it beats the documented shape.
 */
#[TypefinderResource(model: User::class)]
class ModelAttributeAndDocResource extends JsonResource
{
    /**
     * @return array{id: int, name: string}
     */
    public function toArray(Request $request): array
    {
        return [];
    }
}
