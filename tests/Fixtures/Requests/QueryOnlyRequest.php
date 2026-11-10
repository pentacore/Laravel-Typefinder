<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Every rule is a query-string parameter, so the body is empty.
 */
final class QueryOnlyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** @query */
            'page' => ['required', 'integer'],
            /** @query */
            'per_page' => ['integer'],
        ];
    }
}
