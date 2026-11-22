<?php

declare(strict_types=1);

namespace Tests\Fixtures\AbortingRequests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * rules() aborts without a message (#128). It checks the input rather than
 * the route: the extractor's retry stubs the route, so a route check would pass.
 */
class Issue128AbortRequest extends FormRequest
{
    public function rules(): array
    {
        abort_unless($this->has('thing'), 404);

        return ['values' => ['present', 'array']];
    }
}
