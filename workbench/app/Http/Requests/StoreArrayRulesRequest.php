<?php

namespace App\Http\Requests;

use App\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Covers every shape the `array` / `list` rules can take (issue #74).
 */
class StoreArrayRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Bare array rules — no child rules attached.
            'options' => ['sometimes', 'nullable', 'array'],
            'payload' => 'required|array',
            'ids' => ['required', 'list'],
            'contact' => 'array:name,email',
            'headers' => ['array:content-type,accept'],
            'filters' => [Rule::array()],
            'address' => [Rule::array(['street', 'city'])->min(1)],

            // Rule order must not matter for nested / wildcard detection.
            'counts' => ['required', 'min:1', 'list'],
            'counts.*' => ['integer'],
            'profile' => ['array:name,age', 'required'],
            'profile.name' => ['required', 'string'],
            'profile.age' => ['integer'],
            'labels' => [Rule::array()],
            'labels.*' => ['string'],

            // Nested / object-array children that are themselves arrays.
            'meta' => ['array'],
            'meta.title' => ['string'],
            'meta.extra' => ['array'],
            'rows' => ['array'],
            'rows.*.cells' => ['required', 'array'],

            // Wildcard items that are arrays or structured types.
            'matrix' => ['array'],
            'matrix.*' => ['array'],
            'lists' => ['array'],
            'lists.*' => ['list'],
            'sizes' => ['array'],
            'sizes.*' => ['in:s,m,l'],
            'statuses' => ['array'],
            'statuses.*' => [Rule::enum(PostStatus::class)],
            'grades' => ['array'],
            'grades.*' => [Rule::in(['a', 'b'])],

            // `confirmed` copies the resolved type.
            'secret' => ['required', 'array', 'confirmed'],
        ];
    }
}
