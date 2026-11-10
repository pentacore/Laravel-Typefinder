<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The exact rules from issue #101.
 */
class Issue101QueryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'value' => ['required', 'string'],
            /**
             * Read from the query string (PUT /things/{key}?scope=a).
             *
             * @query
             */
            'scope' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
