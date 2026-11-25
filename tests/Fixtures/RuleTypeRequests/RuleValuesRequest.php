<?php

declare(strict_types=1);

namespace Tests\Fixtures\RuleTypeRequests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Tests\Fixtures\Enums\Issue117Priority;

/**
 * Issues #155, #156 and #157: the values `in`, `accepted`, `declined` and
 * `missing` allow.
 */
final class RuleValuesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'size' => ['required', Rule::in([1, 2, 3])],
            'prio' => [Rule::in([Issue117Priority::Low, Issue117Priority::High])],
            'flag' => [Rule::in([true, false])],
            'pair' => [Rule::in(['a,b', 'c'])],
            'greeting' => [Rule::in(['say "hi"', 'x'])],
            'csv' => ['in:"a,b",c'],
            'label' => ['string', Rule::in([1, 2])],
            'terms' => 'accepted',
            'optout' => 'declined',
            'maybe' => 'accepted_if:other,1',
            'gone' => 'missing',
            'gone_if' => ['missing_if:other,1', 'string'],
            'words' => ['integer', Rule::in(['a', 'b'])],
            'numbers' => ['numeric', Rule::in(['1', 2.5])],
            'on' => ['string', Rule::in([true, false])],
            'yes' => [Rule::in([true])],
            'mixed' => [Rule::in(['a', false])],
            'picks' => ['array', Rule::in([1, 2])],
            'tuple_if' => [['required_if', 'other', Issue117Priority::Low], 'string'],
        ];
    }
}
