<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Concerns\ComposesOwner;

/**
 * A trait helper (`User` imported only by the trait) and `self::` /
 * `static::` calls.
 */
class TraitHelperResource extends JsonResource
{
    use ComposesOwner;

    public function toArray(Request $request): array
    {
        return [...$this->ownerPart(), ...self::idPart(), ...static::selfPart()];
    }

    /**
     * @return array{id: int}
     */
    private function idPart(): array
    {
        return ['id' => $this->id];
    }

    /**
     * @return array{self: static}
     */
    protected function selfPart(): array
    {
        return ['self' => $this];
    }
}
