<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests\Delegated;

/**
 * A plain rules holder: not a form request, so it gets no type of its own.
 */
final class FilterRules
{
    public function rules(): array
    {
        return [
            /** @query */
            'filter' => ['string'],
        ];
    }
}
