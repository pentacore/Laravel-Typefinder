<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Attributes;

use Attribute;

/**
 * Override reflection for broadcast events (`ShouldBroadcast` implementations).
 *
 * The extractor discovers broadcast events automatically by walking the
 * configured paths and collecting classes that implement
 * `Illuminate\Contracts\Broadcasting\ShouldBroadcast`. It then calls
 * `broadcastOn()` and `broadcastWith()` on a cheap instance to derive
 * channel and payload types. This attribute is the escape hatch for cases
 * where that runtime reflection isn't reliable — e.g., events whose
 * `broadcastOn()` reads runtime state that isn't available at generation
 * time, or whose `broadcastWith()` returns a shape the static analyzer
 * can't infer.
 *
 * Example — supply a declarative payload when `broadcastWith()` is dynamic:
 * ```php
 * use Pentacore\Typefinder\Attributes\TypefinderBroadcast;
 *
 * #[TypefinderBroadcast(
 *     payload: ['order' => \App\Models\Order::class, 'trackingNumber' => 'string'],
 *     as: 'OrderShipped',
 *     channel: 'orders.{orderId}',
 *     channelType: 'private',
 * )]
 * class OrderShipped implements \Illuminate\Contracts\Broadcasting\ShouldBroadcast { … }
 * ```
 *
 * Generation is opt-in via `config('typefinder.broadcasting.enabled')`.
 * When `$channel` is set, `$channelType` is required. Two events targeting
 * the same channel with the same broadcast name cause the generator to fail.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TypefinderBroadcast
{
    /**
     * @param  array<string, string>  $payload  Prop name → TS type or class-string.
     *                                          When non-empty, replaces the inferred
     *                                          payload entirely.
     * @param  ?string  $as  Broadcast-name override. Maps to Laravel's `broadcastAs()`.
     *                       Defaults to the class short name.
     * @param  ?string  $channel  Channel name override (bypasses `broadcastOn()`).
     *                            Pattern parameters like `{orderId}` pass through verbatim.
     * @param  ?string  $channelType  One of `'public'`, `'private'`, `'presence'`.
     *                                Required when `$channel` is set.
     */
    public function __construct(
        public array $payload = [],
        public ?string $as = null,
        public ?string $channel = null,
        public ?string $channelType = null,
    ) {}
}
