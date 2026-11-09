<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\AnyOf;

/**
 * `Rule::anyOf()` with an array branch (issue #74). AnyOf is Laravel 13+,
 * so on older versions the request has no rules.
 */
class StoreAnyOfArrayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if (! class_exists(AnyOf::class)) {
            return [];
        }

        return [
            'value' => ['required', Rule::anyOf([['array'], ['string']])],
            'choice' => ['required', Rule::anyOf([['list'], ['integer']])],
        ];
    }
}
