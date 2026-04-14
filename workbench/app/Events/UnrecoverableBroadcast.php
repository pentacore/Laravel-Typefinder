<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class UnrecoverableBroadcast implements ShouldBroadcast
{
    public function broadcastOn(): Channel
    {
        throw new \RuntimeException('broadcastOn cannot be evaluated statically');
    }
}
