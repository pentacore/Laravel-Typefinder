<?php

declare(strict_types=1);

namespace Tests\Fixtures\RuleTypeRequests;

use App\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Issues #189, #190 and #191: nullable items and `array:a,b` keys.
 */
final class NestedShapesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tags' => ['required', 'array'],
            'tags.*' => ['nullable', 'string'],
            'states' => ['array'],
            'states.*' => ['nullable', Rule::enum(PostStatus::class)],
            'objs' => ['required', 'array'],
            'objs.*' => ['nullable', 'array'],
            'objs.*.a' => ['required', 'string'],
            'grid' => ['array'],
            'grid.*' => ['array'],
            'grid.*.*' => ['nullable', 'array'],
            'grid.*.*.v' => ['integer'],
            'g' => ['required', 'array:anime,tv,movie'],
            'g.*.notes' => ['present', 'string'],
            'l' => ['required', 'array:anime,tv'],
            'l.*' => ['nullable', 'array'],
            'l.*.*' => ['required', 'string'],
            'l.tv' => ['required', 'array'],
            'pick' => ['required', 'array:provider,model'],
            'pick.provider' => ['required', 'string'],
        ];
    }
}
