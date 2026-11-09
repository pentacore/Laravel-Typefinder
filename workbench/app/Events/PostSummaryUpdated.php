<?php

namespace App\Events;

use App\Http\Resources\PostSummaryResource;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Pentacore\Typefinder\Attributes\TypefinderBroadcast;

/**
 * A resource class-string in a broadcast payload (#75).
 */
#[TypefinderBroadcast(
    payload: ['summary' => PostSummaryResource::class],
    channel: 'posts',
    channelType: 'public',
)]
class PostSummaryUpdated implements ShouldBroadcast
{
    public function broadcastOn(): Channel
    {
        return new Channel('posts');
    }
}
