<?php

declare(strict_types=1);

namespace Tests\Fixtures\RuleTypeRequests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Confirmed;

/**
 * Issues #154 and #162: the key a `confirmed` rule compares with.
 */
final class ConfirmedRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'confirmed:pin_repeat'],
            'user.email' => ['required', 'email', 'confirmed'],
            // Rule::confirmed() is Laravel 13+.
            'code' => ['required', 'string', class_exists(Confirmed::class) ? Rule::confirmed() : 'confirmed'],
            'pw' => ['nullable', 'string', 'confirmed'],
            'own' => ['required', 'string', 'confirmed'],
            'own_confirmation' => ['sometimes', 'string'],
            /** @query */
            'secret' => ['required', 'string', 'confirmed'],
        ];
    }
}
