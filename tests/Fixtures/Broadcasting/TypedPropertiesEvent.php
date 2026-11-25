<?php

declare(strict_types=1);

namespace Tests\Fixtures\Broadcasting;

use App\Enums\PostStatus;
use App\Http\Resources\PostResource;
use App\Models\Post;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Public-property payload types (#165). */
class TypedPropertiesEvent implements ShouldBroadcast
{
    public function __construct(
        public ?string $note,
        public Carbon $at,
        public Collection $items,
        public int|string $key,
        public ?Post $post,
        public mixed $any,
        public \DateTimeInterface $when,
        public ?PostStatus $status,
        public PostResource $resource,
        public \stdClass $other,
        public array $list,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('typed');
    }
}
