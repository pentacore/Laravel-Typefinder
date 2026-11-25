<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Issue #232: `confirmed` inside a `Rule::when()` branch adds the
 * confirmation key, as optional as the key is.
 */
final class Issue232WhenConfirmedRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'set_password' => ['boolean'],
            'password' => Rule::when($this->boolean('set_password'), ['required', 'string', 'confirmed']),
            'pin' => ['required', Rule::when(true, ['string', 'confirmed:pin_repeat'], ['string'])],
        ];
    }
}
