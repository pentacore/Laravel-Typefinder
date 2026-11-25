<?php

declare(strict_types=1);

namespace Tests\Fixtures\Broadcasting;

use App\Models\Post;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** broadcastWith() throws and returns no literal keys (#164). */
class OpaquePayloadEvent implements ShouldBroadcast
{
    public function __construct(public Post $post) {}

    public function broadcastOn(): Channel
    {
        return new Channel('posts');
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->post->toArray();
    }
}
