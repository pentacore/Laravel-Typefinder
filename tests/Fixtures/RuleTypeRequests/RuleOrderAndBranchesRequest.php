<?php

declare(strict_types=1);

namespace Tests\Fixtures\RuleTypeRequests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Issues #158, #160 and #161: `Rule::when()`, `Rule::forEach()`, and rules
 * whose order used to decide the result.
 */
final class RuleOrderAndBranchesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $cond = fn (): bool => true;

        return [
            'when' => Rule::when($cond, ['required', 'integer']),
            'when2' => ['nullable', Rule::when($cond, 'integer', 'string')],
            'both' => [Rule::when($cond, ['required', 'string'], ['required', 'string'])],
            'lines' => ['required', 'array'],
            'lines.*' => Rule::forEach(fn (): array => ['integer']),
            'rows' => ['required', 'array'],
            'rows.*' => Rule::forEach(fn (): array => ['qty' => ['required', 'integer']]),
            'a' => ['required_with:b', 'required'],
            'pin' => ['required', 'digits:4', 'string'],
            'count' => ['required', 'regex:/^\d+$/', 'integer'],
            'items' => ['array'],
            'items.*' => ['nullable', Rule::forEach(fn (): string => 'string|max:5')],
            'broken_when' => [Rule::when($cond, fn (): array => throw new \RuntimeException('needs input'))],
            // 2^7 branch combinations, more than are typed.
            'many' => array_fill(0, 7, Rule::when($cond, 'string')),
            'broken_each' => ['array'],
            'broken_each.*' => Rule::forEach(fn (string $value): array => ['integer']),
        ];
    }
}
