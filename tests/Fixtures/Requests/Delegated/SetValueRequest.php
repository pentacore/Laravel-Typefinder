<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests\Delegated;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The exact delegation from issue #108.
 */
final class SetValueRequest extends FormRequest
{
    public function rules(): array
    {
        return new ApiSetValueRequest()->rules();
    }
}
