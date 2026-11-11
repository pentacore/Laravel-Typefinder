<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use App\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Tests\Fixtures\Enums\Issue117Priority;
use Tests\Fixtures\Enums\Issue117Suit;

final class Issue117EnumSubsetRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'only' => ['required', Rule::enum(PostStatus::class)->only([PostStatus::Draft, PostStatus::Published])],
            'except' => ['required', Rule::enum(PostStatus::class)->except(PostStatus::Archived)],
            // Laravel checks `only` first and then never looks at `except`.
            'both' => ['required', Rule::enum(PostStatus::class)->only(PostStatus::Draft)->except(PostStatus::Draft)],
            'whole' => ['required', Rule::enum(PostStatus::class)],
            'maybe' => ['nullable', Rule::enum(PostStatus::class)->except([PostStatus::Draft])],
            'collected' => ['required', Rule::enum(PostStatus::class)->only(collect([PostStatus::Archived]))],
            'list' => ['required', 'array'],
            'list.*' => [Rule::enum(PostStatus::class)->only([PostStatus::Draft, PostStatus::Published])],
            'priority' => ['required', Rule::enum(Issue117Priority::class)->only([Issue117Priority::Low, Issue117Priority::High])],
            // A pure enum case has no value to narrow with.
            'pure' => ['required', Rule::enum(Issue117Suit::class)->only(Issue117Suit::Hearts)],
        ];
    }
}
