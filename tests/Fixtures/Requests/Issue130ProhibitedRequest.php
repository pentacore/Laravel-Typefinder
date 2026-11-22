<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Issue #130: a `prohibited` key may only be missing or empty.
 */
final class Issue130ProhibitedRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer'],
            'path' => ['prohibited'],
            'legacy' => 'nullable|prohibited',

            // The conditional rules only prohibit the key some of the time.
            'when_if' => ['prohibited_if:id,1', 'string'],
            'when_unless' => ['prohibited_unless:id,1', 'string'],
        ];
    }
}
