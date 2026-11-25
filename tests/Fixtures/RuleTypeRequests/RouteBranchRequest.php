<?php

declare(strict_types=1);

namespace Tests\Fixtures\RuleTypeRequests;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Issue #194: `rules()` that branches on the route.
 */
final class RouteBranchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if (request()->isMethod('PATCH')) {
            return ['draft' => ['sometimes', 'string']];
        }

        return $this->route('post') instanceof Post
            ? ['draft' => ['sometimes', 'array']]
            : ['draft' => ['required', 'array']];
    }
}
