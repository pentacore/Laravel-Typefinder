<?php

declare(strict_types=1);

namespace Tests\Fixtures\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** Int and float constructor properties in a channel name (#168). */
class NumericChannelEvent implements ShouldBroadcast
{
    public function __construct(public int $partyId, public float $zone) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('party.'.$this->partyId.'.zone.'.$this->zone)];
    }
}
