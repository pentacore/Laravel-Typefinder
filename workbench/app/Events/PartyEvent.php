<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base class for every domain event broadcast on a party's private
 * channel. Subclasses set $partyId in their constructor and override
 * broadcastWith() to shape the payload.
 *
 * - ShouldBroadcastNow keeps latency at sync-event levels (matches the
 *   legacy WebSocket protocol's behaviour). Switch to ShouldBroadcast if
 *   we ever want to push these through the queue.
 * - InteractsWithSockets supplies the `dontBroadcastToCurrentUser()`
 *   helper so the originating browser tab doesn't echo its own mutation.
 */
abstract class PartyEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly string $partyId) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('party.'.$this->partyId)];
    }
}
