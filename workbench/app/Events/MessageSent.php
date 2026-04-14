<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class MessageSent implements ShouldBroadcast
{
    public function __construct(public User $user, public string $body) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('chat.{roomId}');
    }
}
