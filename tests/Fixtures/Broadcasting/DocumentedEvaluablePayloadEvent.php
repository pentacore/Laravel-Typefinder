<?php

declare(strict_types=1);

namespace Tests\Fixtures\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * broadcastWith() runs without the constructor, but its PHPDoc shape is more
 * precise than the values it returns then (#249).
 */
class DocumentedEvaluablePayloadEvent implements ShouldBroadcast
{
    public function broadcastOn(): Channel
    {
        return new Channel('things');
    }

    /**
     * @return array{ids: list<int>, label: 'a'|'b'}
     */
    public function broadcastWith(): array
    {
        return ['ids' => [], 'label' => 'a'];
    }
}
