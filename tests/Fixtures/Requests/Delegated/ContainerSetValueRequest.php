<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests\Delegated;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Takes rules from classes resolved from the container.
 */
final class ContainerSetValueRequest extends FormRequest
{
    public function rules(): array
    {
        $holder = new FilterRules;

        return array_merge(
            ['value' => ['required', 'string']],
            app(PagingRules::class)->rules(),
            resolve(SortRules::class)->rules(),
            // Not followed: the class cannot be read off these calls.
            value($holder)->rules(),
            $holder->rules(),
        );
    }
}
