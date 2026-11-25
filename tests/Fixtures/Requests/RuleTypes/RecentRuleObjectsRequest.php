<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests\RuleTypes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Tests\Fixtures\Enums\Issue117Priority;

/**
 * Issues #152 and #159: rule objects only recent Laravel versions have.
 */
final class RecentRuleObjectsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'site' => ['required', Rule::url()],
            'ref' => ['required', Rule::uuid()],
            'name' => ['required', Rule::string()],
            'any' => [Rule::anyOf([['string'], [Rule::enum(Issue117Priority::class)]])],
        ];
    }
}
