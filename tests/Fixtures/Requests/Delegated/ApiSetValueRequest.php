<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests\Delegated;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The request other requests take their rules from (issue #108).
 */
class ApiSetValueRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'value' => ['present'],
            /**
             * @query
             */
            'scope' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
