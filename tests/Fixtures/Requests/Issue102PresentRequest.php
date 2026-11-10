<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Issue #102: `present` requires the key to be in the input.
 */
final class Issue102PresentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'value' => ['present'],
            'items' => ['present', 'array'],
            'note' => 'present|nullable|string',

            // The conditional family only requires the key some of the time.
            'when_if' => ['present_if:value,1', 'string'],
            'when_unless' => ['present_unless:value,1', 'string'],
            'when_with' => ['present_with:value'],
            'when_with_all' => ['present_with_all:value,items'],
        ];
    }
}
