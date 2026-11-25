<?php

declare(strict_types=1);

namespace Tests\Fixtures\Broadcasting;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Carbon;

/** broadcastWith() values are typed from what they are, never used as types (#163). */
class LiteralPayloadEvent implements ShouldBroadcast
{
    public function broadcastOn(): Channel
    {
        return new Channel('literals');
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'action' => 'created',
            'label' => 'Hello world',
            'count' => 5,
            'ratio' => 1.5,
            'done' => true,
            'none' => null,
            'tags' => ['a', 'b'],
            'meta' => ['k' => 1],
            'status' => PostStatus::Draft,
            'post' => new Post,
            'at' => Carbon::create(2026),
            'other' => new \stdClass,
        ];
    }
}
