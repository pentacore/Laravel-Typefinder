<?php

declare(strict_types=1);

namespace Tests\Fixtures\AbortingRequests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * rules() aborts without a message when the route parameter is missing (#128).
 */
class Issue128AbortRequest extends FormRequest
{
    public function rules(): array
    {
        abort_unless($this->route('thing') !== null, 404);

        return ['values' => ['present', 'array']];
    }
}
