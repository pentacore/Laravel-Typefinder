<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use App\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Every way a rule key can (and cannot) be tagged `@query` (issue #101).
 */
final class QueryRulesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string'],

            /** @query */
            'page' => ['integer'],

            // A tagged parent takes the keys below it along.
            /** @query */
            'filter' => ['array'],
            'filter.status' => [Rule::enum(PostStatus::class)],
            'filter.tags.*' => ['string'],

            // So does a tagged key below a parent.
            'sort.by' => ['string'],
            /** @query */
            'sort.direction' => ['in:asc,desc'],

            // Only a doc comment carries the tag: @query
            'body' => ['string'],

            /** @queryable is another tag */
            'meta' => ['string'],

            // A body field whose nested type would be named like the query type.
            'query' => ['array'],
            'query.term' => ['string'],
        ];
    }
}
