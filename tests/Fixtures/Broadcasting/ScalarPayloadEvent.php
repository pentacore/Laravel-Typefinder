<?php

declare(strict_types=1);

namespace Tests\Fixtures\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** broadcastWith() that returns something other than an array. */
class ScalarPayloadEvent implements ShouldBroadcast
{
    public function broadcastOn(): Channel
    {
        return new Channel('scalar');
    }

    public function broadcastWith(): string
    {
        return 'not an array';
    }
}
