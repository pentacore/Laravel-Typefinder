<?php

namespace App\Http\Requests;

use App\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rules nested more than one level deep (issue #88).
 */
class StoreNestedRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The reproduction from the issue.
            'request' => ['required', 'array'],
            'request.method' => ['required', 'string'],
            'request.headers' => ['sometimes', 'array'],
            'request.headers.*.name' => ['required', 'string'],
            'request.headers.*.value' => ['nullable', 'string'],
            'request.meta' => ['sometimes', 'array'],
            'request.meta.source' => ['required', 'string'],
            'items' => ['required', 'array'],
            'items.*.sku' => ['required', 'string'],
            'items.*.address' => ['sometimes', 'array'],
            'items.*.address.city' => ['required', 'string'],

            // Four levels, plain and wildcard segments mixed, nullable parents.
            'order' => ['nullable', 'array'],
            'order.customer' => ['required', 'array'],
            'order.customer.contacts' => ['nullable', 'array'],
            'order.customer.contacts.*.phones' => ['array'],
            'order.customer.contacts.*.phones.*' => ['string'],
            'order.customer.contacts.*.kind' => ['required', Rule::in(['home', 'work'])],
            'order.customer.contacts.*.status' => [Rule::enum(PostStatus::class)],

            // Wildcard directly below a wildcard.
            'grid' => ['array'],
            'grid.*.*' => ['integer'],
            'boards' => ['required', 'array'],
            'boards.*.*.label' => ['required', 'string'],

            // Parents that have no rule of their own.
            'shipping.address.street' => ['required', 'string'],
            'shipping.address.zip' => ['string'],
            'shipping.notes.*' => ['string'],

            // Keys that are not TypeScript identifiers, and an escaped dot.
            'http-headers' => ['array'],
            'http-headers.content-type' => ['string'],
            'http-headers.x-forwarded-for.*' => ['ip'],
            'config' => ['array'],
            'config.app\.name' => ['string'],
        ];
    }
}
