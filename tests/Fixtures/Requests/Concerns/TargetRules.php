<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests\Concerns;

/**
 * Rules shared by a GET request (query keys) and a POST request (body keys),
 * from issue #129.
 */
trait TargetRules
{
    protected function targetRules(): array
    {
        return [
            'service' => ['required', 'string'],
            'item_id' => ['required', 'integer'],
        ];
    }
}
