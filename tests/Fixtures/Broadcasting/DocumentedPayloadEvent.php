<?php

declare(strict_types=1);

namespace Tests\Fixtures\Broadcasting;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** broadcastWith() declares its payload with a PHPDoc shape (#249). */
class DocumentedPayloadEvent implements ShouldBroadcast
{
    public function __construct(public Post $post) {}

    public function broadcastOn(): Channel
    {
        return new Channel('posts');
    }

    /**
     * @return array{id: int, title: string, status: PostStatus, post: Post, note?: string|null, tags: list<string>, run: callable}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->post->id,
            'title' => $this->post->title,
            'status' => $this->post->status,
            'post' => $this->post,
            'tags' => [],
            'run' => fn (): null => null,
        ];
    }
}
