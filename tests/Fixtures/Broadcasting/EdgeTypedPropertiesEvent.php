<?php

declare(strict_types=1);

namespace Tests\Fixtures\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** Literal, self, intersection and mixed-union property types (#165). */
class EdgeTypedPropertiesEvent implements ShouldBroadcast
{
    public function __construct(
        public true $yes,
        public ?false $no,
        public ?self $previous,
        public \Countable&\Traversable $both,
        public int|\stdClass $mixed,
        public int|string|null $either,
    ) {}

    public function broadcastOn(): Channel
    {
        // Throws on the bare instance; the prefilled one gives the union-typed
        // property null.
        return new Channel('edge'.$this->either);
    }
}
