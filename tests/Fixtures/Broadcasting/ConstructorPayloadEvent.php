<?php

declare(strict_types=1);

namespace Tests\Fixtures\Broadcasting;

use App\Models\Post;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** broadcastWith() reads constructor state, so it throws without the constructor (#164). */
class ConstructorPayloadEvent implements ShouldBroadcast
{
    public function __construct(public Post $post, public string $secret) {}

    public function broadcastOn(): Channel
    {
        return new Channel('posts');
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->post->id, 'title' => $this->post->title];
    }
}
