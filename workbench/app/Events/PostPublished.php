<?php

namespace App\Events;

use App\Models\Post;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class PostPublished implements ShouldBroadcast
{
    public function __construct(public Post $post) {}

    public function broadcastOn(): Channel
    {
        return new Channel('posts');
    }
}
