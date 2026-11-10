<?php

declare(strict_types=1);

namespace Tests\Fixtures\QueryRequests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `@query` tags on rule keys that cannot be read from the source. Kept out of
 * Fixtures/Requests: it warns, and tests there count the warnings.
 */
final class DynamicQueryKeyRequest extends FormRequest
{
    private const string CONSTANT_KEY = 'constant';

    public function rules(): array
    {
        $prefix = 'computed';

        return [
            'title' => ['required', 'string'],
            /** @query */
            self::CONSTANT_KEY => ['string'],
            /** @query */
            $prefix.'_key' => ['string'],
            /** @query */
            'page' => ['integer'],
        ];
    }
}
