<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use App\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class Issue122RuleOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The type rule comes first, as IDE helpers and linters suggest.
            'folder' => ['string', Rule::in(['out', 'in', 'processed'])],
            'kind' => ['required', 'string', 'in:a,b'],
            'mode' => [Rule::in(['x', 'y']), 'string'],
            'status' => ['required', 'string', Rule::enum(PostStatus::class)],

            // A numeric field keeps number literals, in either order.
            'level' => ['required', 'integer', 'in:1,2,3'],
            'rank' => ['required', Rule::in([1, 2]), 'integer'],

            // On an array, `in` checks every item.
            'sizes' => ['required', 'array', Rule::in(['s', 'm'])],
            'tags' => ['required', Rule::in(['a', 'b']), 'array'],

            // A list of values does not narrow a type it cannot be written in.
            'flag' => ['required', 'boolean', 'in:1'],
            'count' => ['required', 'integer', 'in:few,many'],
        ];
    }
}
