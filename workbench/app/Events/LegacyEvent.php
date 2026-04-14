<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;

#[TypefinderIgnore]
class LegacyEvent implements ShouldBroadcast
{
    public function broadcastOn(): Channel
    {
        return new Channel('legacy');
    }
}
