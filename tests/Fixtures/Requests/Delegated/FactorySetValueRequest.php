<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests\Delegated;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Delegates through a static factory, next to rules of its own.
 */
final class FactorySetValueRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            ...ApiSetValueRequest::createFrom($this)->rules(),
            /** @query */
            'page' => ['integer'],
            'note' => ['string'],
        ];
    }
}
