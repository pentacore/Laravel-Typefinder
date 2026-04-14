<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TypefinderBroadcast
{
    /**
     * @param  array<string, string>  $payload  Prop name → TS type or FQCN. Overrides reflection.
     * @param  ?string  $as  Broadcast-name override (Laravel's broadcastAs()).
     * @param  ?string  $channel  Channel name override when broadcastOn() is unreliable.
     * @param  ?string  $channelType  'public' | 'private' | 'presence'. Required when $channel is set.
     */
    public function __construct(
        public array $payload = [],
        public ?string $as = null,
        public ?string $channel = null,
        public ?string $channelType = null,
    ) {}
}
