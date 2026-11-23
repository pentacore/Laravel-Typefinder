<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A tagged spread whose method takes more keys from a nested spread.
 */
final class NestedSpreadQueryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            /** @query */
            ...$this->listingRules(),
        ];
    }

    private function listingRules(): array
    {
        return ['page' => ['integer'], ...$this->sortRules()];
    }

    private function sortRules(): array
    {
        return [
            'sort' => ['string'],
            'direction' => ['in:asc,desc'],
        ];
    }
}
