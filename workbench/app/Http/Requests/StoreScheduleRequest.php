<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rate_limits' => ['nullable', 'array'],
            'rate_limits.*.metric' => ['required', 'string'],
            'rate_limits.*.period' => ['required', 'string'],
            'rate_limits.*.limit_value' => ['required', 'integer', 'min:1'],
            'tags' => ['array'],
            'tags.*' => ['string'],
            'matrix' => ['array'],
            'matrix.*.*' => ['integer'],
        ];
    }
}
