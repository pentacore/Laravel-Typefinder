<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Tests\Fixtures\Requests\Concerns\TargetRules;

/**
 * Issue #129: a `@query` tag on a spread tags the keys the spread brings in.
 */
final class Issue129ReleaseQueryRequest extends FormRequest
{
    use TargetRules;

    public function rules(): array
    {
        return [
            /** @query */
            ...$this->targetRules(),
            /** @query */
            'season' => ['nullable', 'integer'],
        ];
    }
}
