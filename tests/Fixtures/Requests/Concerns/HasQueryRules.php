<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests\Concerns;

trait HasQueryRules
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            /** @query */
            'cursor' => ['string'],
        ];
    }
}
