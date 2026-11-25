<?php

declare(strict_types=1);

namespace Tests\Fixtures\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** An abstract event is skipped silently (#172). */
abstract class BaseEvent implements ShouldBroadcast
{
    public function broadcastOn(): Channel
    {
        return new Channel('base');
    }
}
