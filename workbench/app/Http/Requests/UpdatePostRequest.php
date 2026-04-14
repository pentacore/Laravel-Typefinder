<?php

namespace App\Http\Requests;

use App\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string'],
            'status' => ['sometimes', Rule::enum(PostStatus::class)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'password' => ['sometimes', 'string', 'confirmed'],
        ];
    }
}
