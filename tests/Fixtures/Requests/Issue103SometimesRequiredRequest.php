<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Issue #103: `sometimes` lets the key be left out, whatever follows it.
 */
final class Issue103SometimesRequiredRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string'],
            'body' => 'sometimes|required|string',
            'plain' => ['sometimes', 'string'],
            'flag' => ['sometimes', 'present', 'boolean'],
            'late' => ['required', 'integer', 'sometimes'],

            // A parent that may be left out keeps its own required keys.
            'meta' => ['sometimes', 'required', 'array'],
            'meta.source' => ['required', 'string'],
        ];
    }
}
