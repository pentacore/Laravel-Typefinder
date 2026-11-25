<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Issue #230: an `in` rule with no values (often built from input that is
 * empty when Typefinder calls `rules()`) keeps the declared type.
 */
final class Issue230EmptyInRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'pick' => ['nullable', 'integer', Rule::in([])],
            'other' => ['required', 'integer', Rule::in((array) $this->input('ids', []))],
            'label' => ['required', 'string', Rule::in([])],
            'bare' => ['required', Rule::in([])],
            'tags' => ['required', 'array', Rule::in([])],
            'raw' => 'required|string|in:',
        ];
    }
}
