<?php

declare(strict_types=1);

namespace Tests\Fixtures\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** broadcastAs() returning a backed enum (#167). */
class ThingCreated implements ShouldBroadcast
{
    public function broadcastOn(): Channel
    {
        return new Channel('things');
    }

    public function broadcastAs(): EventName
    {
        return EventName::Created;
    }
}
