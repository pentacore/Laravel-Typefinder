<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The exact rules from issue #88.
 */
final class Issue88ReproRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'request' => ['required', 'array'],
            'request.method' => ['required', 'string'],
            'request.headers' => ['sometimes', 'array'],
            'request.headers.*.name' => ['required', 'string'],
            'request.headers.*.value' => ['nullable', 'string'],
            'request.meta' => ['sometimes', 'array'],
            'request.meta.source' => ['required', 'string'],
            'items' => ['required', 'array'],
            'items.*.sku' => ['required', 'string'],
            'items.*.address' => ['sometimes', 'array'],
            'items.*.address.city' => ['required', 'string'],
        ];
    }
}
