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
 * `broadcastOn()` and `broadcastWith()` on an instance built without its
 * constructor. The payload is typed from the values `broadcastWith()`
 * returns (a string is `string`, a model, backed enum or resource its
 * generated type, anything else `unknown` with a warning). When
 * `broadcastWith()` cannot run without the constructor, the literal keys it
 * returns are read from source and typed `unknown`, with a warning. An event
 * without `broadcastWith()` is typed from its public properties. This
 * attribute is the escape hatch for cases where that reflection isn't
 * enough — e.g., events whose `broadcastOn()` reads runtime state that isn't
 * available at generation time, or whose `broadcastWith()` payload should
 * be typed precisely.
 *
 * Example — supply a declarative payload when `broadcastWith()` is dynamic:
 * <pre>
 * use Pentacore\Typefinder\Attributes\TypefinderBroadcast;
 *
 * #[TypefinderBroadcast(
 *     payload: ['order' => \App\Models\Order::class, 'trackingNumber' => 'string'],
 *     as: 'OrderShipped',
 *     channel: 'orders.{orderId}',
 *     channelType: 'private',
 * )]
 * class OrderShipped implements \Illuminate\Contracts\Broadcasting\ShouldBroadcast { … }
 * </pre>
 *
 * Generation is opt-in via `config('typefinder.broadcasting.enabled')`.
 * When `$channel` is set, `$channelType` is required. Two events with the
 * same broadcast name cause the generator to fail, whatever their channels.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TypefinderBroadcast
{
    /**
     * @param  array<string, string>  $payload  Prop name → TS type or class-string.
     *                                          When non-empty, replaces the inferred
     *                                          payload entirely.
     * @param  ?string  $as  Broadcast-name override. Maps to Laravel's `broadcastAs()`.
     *                       Defaults to what `broadcastAs()` returns (a backed
     *                       enum's value), else the class short name.
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
