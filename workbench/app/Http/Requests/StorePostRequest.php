<?php

namespace App\Http\Requests;

use App\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'status' => ['required', Rule::enum(PostStatus::class)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'metadata' => ['nullable', 'array'],
            'metadata.key' => ['string'],
            'metadata.value' => ['string'],
            'publish_now' => ['sometimes', 'accepted'],
            'category' => ['required', Rule::in(['tech', 'science', 'art'])],
        ];
    }
}
