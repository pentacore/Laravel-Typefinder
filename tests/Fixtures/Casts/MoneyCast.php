<?php

declare(strict_types=1);

namespace Tests\Fixtures\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Pentacore\Typefinder\Attributes\TypefinderCast;

/**
 * Cast that takes an argument (`MoneyCast::class.':EUR'`), so Eloquent hands
 * the resolver the parameterised string rather than the bare class name.
 */
#[TypefinderCast('{ amount: number; currency: string }')]
final readonly class MoneyCast implements CastsAttributes
{
    public function __construct(private string $currency = 'EUR') {}

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return ['amount' => $value, 'currency' => $this->currency];
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }
}
