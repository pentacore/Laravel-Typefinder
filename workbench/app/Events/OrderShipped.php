<?php

namespace App\Events;

use App\Models\Post;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class OrderShipped implements ShouldBroadcast
{
    public function __construct(public int $orderId, public string $trackingNumber) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('orders.{orderId}');
    }

    public function broadcastAs(): string
    {
        return 'OrderShipped';
    }

    public function broadcastWith(): array
    {
        return [
            'order' => Post::class,
            'trackingNumber' => 'string',
        ];
    }
}
