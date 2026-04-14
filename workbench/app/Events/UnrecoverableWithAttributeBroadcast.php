<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Pentacore\Typefinder\Attributes\TypefinderBroadcast;

#[TypefinderBroadcast(
    payload: ['id' => 'number', 'reason' => 'string'],
    channel: 'audit',
    channelType: 'private',
    as: 'AuditFired',
)]
class UnrecoverableWithAttributeBroadcast implements ShouldBroadcast
{
    public function broadcastOn(): Channel
    {
        throw new \RuntimeException('broadcastOn cannot be evaluated statically');
    }
}
