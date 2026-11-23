<?php

declare(strict_types=1);

namespace Tests\Fixtures\QueryRequests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A tagged spread whose keys cannot be read from the source. Kept out of
 * Fixtures/Requests: it warns, and tests there count the warnings.
 */
final class UnreadableSpreadQueryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string'],
            /** @query */
            ...$this->builtRules(),
        ];
    }

    private function builtRules(): array
    {
        return collect(['page' => ['integer']])->all();
    }
}
