<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rules whose shape cannot be expressed as a TypeScript object type.
 */
final class UnexpressibleRulesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string'],

            // The request body itself is a list.
            '*.name' => ['string'],

            // Wildcard and named keys below the same parent.
            'mixed' => ['array'],
            'mixed.*.sku' => ['required', 'string'],
            'mixed.total' => ['integer'],
            'deep' => ['array'],
            'deep.inner' => ['array'],
            'deep.inner.*' => ['string'],
            'deep.inner.count' => ['integer'],
        ];
    }
}
