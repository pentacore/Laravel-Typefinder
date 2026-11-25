<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Extractors;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Collection;
use Pentacore\Typefinder\Analysis\ReturnedArrayReader;
use Pentacore\Typefinder\Attributes\TypefinderBroadcast;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Support\ClassDiscovery;
use Pentacore\Typefinder\Support\ParsedSource;
use PhpParser\Node\Stmt\ClassMethod;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;
use Throwable;

class BroadcastExtractor
{
    /**
     * First value of the integers {@see self::sentinelFor()} gives int and
     * float properties; ten digits each, so one never contains another.
     */
    private const int INT_SENTINEL_BASE = 7_301_000_000;

    /** @var ?Closure(string): void */
    private readonly ?Closure $onWarn;

    /** @var list<string> warnings of the event being extracted */
    private array $warnings = [];

    /** @var array<string, string> sentinel value => `{name}` placeholder */
    private array $intSentinels = [];

    /**
     * @param  null|callable(string): void  $onWarn  Receives warnings about payload
     *                                               entries typed `unknown`.
     */
    public function __construct(?callable $onWarn = null)
    {
        $this->onWarn = $onWarn === null ? null : $onWarn(...);
    }

    /**
     * @return ?array{event_class: class-string, broadcast_name: string, channels: list<array{type: string, name: string}>, payload: array<string, string>}
     */
    public function extract(string $eventClass): ?array
    {
        $reflectionClass = new ReflectionClass($eventClass);

        if (! $reflectionClass->implementsInterface(ShouldBroadcast::class)) {
            return null;
        }

        if ($reflectionClass->getAttributes(TypefinderIgnore::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
            return null;
        }

        $this->warnings = [];
        $attribute = $this->getAttribute($reflectionClass);
        $instance = $this->cheapInstance($reflectionClass);

        $broadcastName = $attribute?->as
            ?? $this->broadcastAs($instance)
            ?? $reflectionClass->getShortName();

        if ($attribute?->channel !== null) {
            $channels = [['type' => $attribute->channelType ?? 'public', 'name' => $attribute->channel]];
        } else {
            $channels = $this->resolveChannels($instance);
            if ($channels === null) {
                // broadcastOn likely interpolates uninit ctor-promoted props
                // (e.g. `'party.'.$this->partyId`). Retry with a sentinel-prefilled
                // instance so the channel template comes out as a literal
                // (`'party.{partyId}'`), matching the OrderShipped convention.
                $prefilled = $reflectionClass->newInstanceWithoutConstructor();
                $this->prefillUninitializedProperties($prefilled, $reflectionClass);
                $channels = $this->withPlaceholders($this->resolveChannels($prefilled));
            }

            if ($channels === null) {
                throw new RuntimeException('Could not resolve channels for '.$eventClass);
            }
        }

        $payload = $attribute instanceof TypefinderBroadcast && $attribute->payload !== []
            ? $attribute->payload
            : $this->resolvePayload($instance, $reflectionClass);

        if ($this->onWarn instanceof Closure) {
            array_map($this->onWarn, $this->warnings);
        }

        return [
            'event_class' => $eventClass,
            'broadcast_name' => $broadcastName,
            'channels' => $channels,
            'payload' => $payload,
        ];
    }

    /**
     * Whether a class gets a broadcast event type: it implements
     * `ShouldBroadcast`, is not abstract and is not `#[TypefinderIgnore]`d.
     *
     * @param  class-string  $eventClass
     */
    public function isEmittable(string $eventClass): bool
    {
        $reflectionClass = new ReflectionClass($eventClass);

        return $reflectionClass->implementsInterface(ShouldBroadcast::class)
            && ! $reflectionClass->isAbstract()
            && $reflectionClass->getAttributes(TypefinderIgnore::class, ReflectionAttribute::IS_INSTANCEOF) === [];
    }

    /**
     * {@see self::extract()}, or null when the event cannot be extracted:
     * `$onWarn` is told why, so one event never stops the others.
     *
     * @param  class-string  $eventClass
     * @param  null|callable(string, Throwable): void  $onWarn
     * @return ?array{event_class: class-string, broadcast_name: string, channels: list<array{type: string, name: string}>, payload: array<string, string>}
     */
    public function tryExtract(string $eventClass, ?callable $onWarn = null): ?array
    {
        try {
            return $this->extract($eventClass);
        } catch (Throwable $throwable) {
            if ($onWarn !== null) {
                $onWarn($eventClass, $throwable);
            }

            return null;
        }
    }

    /**
     * @return list<array{event_class: class-string, broadcast_name: string, channels: list<array{type: string, name: string}>, payload: array<string, string>}>
     */
    public function extractFromDirectory(string $path, ?callable $onExtract = null, ?callable $onWarn = null): array
    {
        $results = [];

        foreach (ClassDiscovery::in($path, $onWarn) as $className) {
            if (! $this->isEmittable($className)) {
                continue;
            }

            if ($onExtract !== null) {
                $onExtract($className);
            }

            $entry = $this->tryExtract($className, $onWarn);
            if ($entry !== null) {
                $results[] = $entry;
            }
        }

        return $results;
    }

    protected function getAttribute(ReflectionClass $reflectionClass): ?TypefinderBroadcast
    {
        $attrs = $reflectionClass->getAttributes(TypefinderBroadcast::class, ReflectionAttribute::IS_INSTANCEOF);

        return $attrs === [] ? null : $attrs[0]->newInstance();
    }

    /**
     * Instantiate without running the constructor. Events commonly accept
     * typed model instances in __construct (`public function __construct(Post $post)`)
     * which we can't fabricate; bypassing the ctor lets us still reflect
     * on broadcastOn()/broadcastWith()/public property types.
     */
    protected function cheapInstance(ReflectionClass $reflectionClass): object
    {
        return $reflectionClass->newInstanceWithoutConstructor();
    }

    protected function prefillUninitializedProperties(object $instance, ReflectionClass $reflectionClass): void
    {
        $this->intSentinels = [];

        foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
            if ($reflectionProperty->isStatic()) {
                continue;
            }

            if ($reflectionProperty->isInitialized($instance)) {
                continue;
            }

            $sentinel = $this->sentinelFor($reflectionProperty);
            if ($sentinel === self::NO_SENTINEL) {
                continue;
            }

            // PHP 8.3 forbids ReflectionProperty::setValue() on a readonly
            // property from outside the declaring class scope (8.4+ relaxed
            // this). Bind a setter closure to the declaring class so the
            // assignment runs in the right scope on every supported version.
            $declaringClass = $reflectionProperty->getDeclaringClass()->getName();
            $name = $reflectionProperty->getName();
            $setter = Closure::bind(
                static function (object $object) use ($name, $sentinel): void {
                    $object->{$name} = $sentinel;
                },
                null,
                $declaringClass,
            );

            try {
                $setter($instance);
            } catch (Throwable) {
                // readonly already-initialized or unsupported — leave uninit.
            }
        }
    }

    private const string NO_SENTINEL = "\0__typefinder_no_sentinel__\0";

    protected function sentinelFor(ReflectionProperty $reflectionProperty): null|string|array|int|float|false
    {
        $type = $reflectionProperty->getType();
        if (! $type instanceof ReflectionNamedType) {
            return $type instanceof ReflectionType && $type->allowsNull() ? null : self::NO_SENTINEL;
        }

        $name = $type->getName();

        return match ($name) {
            'string' => '{'.$reflectionProperty->getName().'}',
            'int', 'float' => $this->intSentinel($reflectionProperty->getName(), $name),
            'bool' => false,
            'array' => [],
            default => $type->allowsNull() ? null : self::NO_SENTINEL,
        };
    }

    /**
     * A number no channel name would contain by chance, remembered so
     * {@see self::withPlaceholders()} can turn it into `{name}` (an int
     * property cannot hold the `{name}` string itself).
     */
    private function intSentinel(string $property, string $type): int|float
    {
        $value = self::INT_SENTINEL_BASE + count($this->intSentinels);
        $this->intSentinels[(string) $value] = '{'.$property.'}';

        return $type === 'float' ? (float) $value : $value;
    }

    /**
     * @param  ?list<array{type: string, name: string}>  $channels
     * @return ?list<array{type: string, name: string}>
     */
    private function withPlaceholders(?array $channels): ?array
    {
        if ($channels === null || $this->intSentinels === []) {
            return $channels;
        }

        return array_map(
            fn (array $channel): array => ['type' => $channel['type'], 'name' => strtr($channel['name'], $this->intSentinels)],
            $channels,
        );
    }

    /**
     * The name `broadcastAs()` returns; a backed enum gives its value, as
     * Laravel's `BroadcastEvent` reads it with `enum_value()`.
     */
    protected function broadcastAs(object $instance): ?string
    {
        if (! method_exists($instance, 'broadcastAs')) {
            return null;
        }

        try {
            $value = $instance->broadcastAs();
        } catch (Throwable) {
            return null;
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    /**
     * @return ?list<array{type: string, name: string}>
     */
    protected function resolveChannels(object $instance): ?array
    {
        if (! method_exists($instance, 'broadcastOn')) {
            return null;
        }

        try {
            $channels = $instance->broadcastOn();
        } catch (Throwable) {
            return null;
        }

        $channels = is_array($channels) ? $channels : [$channels];
        $result = [];
        foreach ($channels as $channel) {
            $type = match (true) {
                $channel instanceof PresenceChannel => 'presence',
                $channel instanceof PrivateChannel => 'private',
                $channel instanceof Channel => 'public',
                default => 'public',
            };
            $rawName = property_exists($channel, 'name') ? (string) $channel->name : (string) $channel;
            // PrivateChannel/PresenceChannel prefix the stored name with their
            // visibility. We track visibility separately, so strip the prefix.
            $name = match ($type) {
                'private' => str_starts_with($rawName, 'private-') ? substr($rawName, 8) : $rawName,
                'presence' => str_starts_with($rawName, 'presence-') ? substr($rawName, 9) : $rawName,
                default => $rawName,
            };
            $result[] = ['type' => $type, 'name' => $name];
        }

        return $result;
    }

    /**
     * The payload Laravel sends: what `broadcastWith()` returns when the
     * event has one, else its public properties.
     *
     * @return array<string, string>
     */
    protected function resolvePayload(object $instance, ReflectionClass $reflectionClass): array
    {
        if (method_exists($instance, 'broadcastWith')) {
            try {
                $with = $instance->broadcastWith();
            } catch (Throwable) {
                // Built without its constructor, the event usually cannot
                // evaluate broadcastWith(): read the keys it returns instead.
                return $this->payloadFromSource($reflectionClass);
            }

            if (! is_array($with)) {
                throw new RuntimeException(sprintf('%s::broadcastWith() does not return an array; declare the payload with #[TypefinderBroadcast(payload: …)]', $reflectionClass->getName()));
            }

            return $this->payloadFromValues($with, $reflectionClass->getName());
        }

        // Framework-internal trait properties (e.g. `$socket` from
        // InteractsWithSockets) always end up on the wire but are noise
        // users don't want to type against — collect their names up front.
        $traitInjectedNames = $this->traitInjectedPropertyNames($reflectionClass);

        $payload = [];
        foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
            if ($reflectionProperty->isStatic()) {
                continue;
            }

            $name = $reflectionProperty->getName();
            if (in_array($name, $traitInjectedNames, true)) {
                continue;
            }

            $type = $this->typeToTsHint($reflectionProperty->getType(), $reflectionProperty->getDeclaringClass()->getName());
            if ($type === null) {
                $this->warnUnknown($reflectionClass->getName(), $name, sprintf('property $%s is typed %s, which has no TypeScript type', $name, (string) $reflectionProperty->getType()));
                $type = 'unknown';
            }

            $payload[$name] = $type;
        }

        return $payload;
    }

    /**
     * The type of each value `broadcastWith()` returned.
     *
     * @param  array<array-key, mixed>  $with
     * @return array<string, string>
     */
    private function payloadFromValues(array $with, string $eventClass): array
    {
        $payload = [];
        foreach ($with as $key => $value) {
            $type = $this->valueType($value);
            if ($type === null) {
                $this->warnUnknown($eventClass, (string) $key, sprintf('broadcastWith() returns a %s for it', get_debug_type($value)));
                $type = 'unknown';
            }

            $payload[(string) $key] = $type;
        }

        return $payload;
    }

    /**
     * The literal keys of the arrays `broadcastWith()` returns, read from
     * its source, each typed `unknown`.
     *
     * @return array<string, string>
     */
    private function payloadFromSource(ReflectionClass $reflectionClass): array
    {
        $eventClass = $reflectionClass->getName();
        $method = $this->methodNode(new ReflectionMethod($eventClass, 'broadcastWith'));
        $returns = $method instanceof ClassMethod ? new ReturnedArrayReader()->returns($method) : [];

        $keys = [];
        $open = false;
        foreach ($returns as $returned) {
            $open = $open || $returned->isOpen();
            foreach ($returned->keys() as $key) {
                $keys[$key['key']] = 'unknown';
            }
        }

        if ($keys === []) {
            throw new RuntimeException(sprintf('%s::broadcastWith() cannot be evaluated without the constructor and returns no literal keys; declare the payload with #[TypefinderBroadcast(payload: …)]', $eventClass));
        }

        $this->warnings[] = sprintf(
            '%s: broadcastWith() cannot be evaluated without the constructor, so the literal keys it returns are typed unknown%s. Declare the payload with #[TypefinderBroadcast(payload: …)].',
            $eventClass,
            $open ? ' (it also returns keys that cannot be read statically)' : '',
        );

        return $keys;
    }

    private function methodNode(ReflectionMethod $reflectionMethod): ?ClassMethod
    {
        $file = $reflectionMethod->getFileName();
        if ($file === false) {
            return null;
        }

        return ParsedSource::forFile($file)?->methodNode($reflectionMethod->getDeclaringClass()->getName(), $reflectionMethod->getName());
    }

    private function warnUnknown(string $eventClass, string $key, string $reason): void
    {
        $this->warnings[] = sprintf(
            '%s: payload key "%s" is typed unknown: %s. Declare the payload with #[TypefinderBroadcast(payload: …)].',
            $eventClass,
            $key,
            $reason,
        );
    }

    /** The TypeScript type of a value as it goes over the wire; null when unknown. */
    private function valueType(mixed $value): ?string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => $value === [] || array_is_list($value) ? 'unknown[]' : 'Record<string, unknown>',
            is_object($value) => $this->classType($value::class),
            default => null,
        };
    }

    /**
     * The TypeScript type of an instance of $class once serialized: a model,
     * backed enum or resource by reference (the renderer resolves the class
     * name), a date as its string, a collection as an array. Null for any
     * other class.
     */
    private function classType(string $class): ?string
    {
        return match (true) {
            is_a($class, DateTimeInterface::class, true) => 'string',
            is_a($class, Collection::class, true) => 'unknown[]',
            is_subclass_of($class, Model::class),
            is_subclass_of($class, BackedEnum::class),
            is_subclass_of($class, JsonResource::class) && ! is_a($class, ResourceCollection::class, true) => $class,
            default => null,
        };
    }

    /**
     * Collect property names contributed by Laravel's framework-internal
     * broadcast traits (currently just InteractsWithSockets). Walks the
     * class hierarchy and trait composition so trait-of-trait inclusion
     * is also detected.
     *
     * @return list<string>
     */
    protected function traitInjectedPropertyNames(ReflectionClass $reflectionClass): array
    {
        $names = [];
        $stack = [$reflectionClass];
        $seen = [];

        while ($stack !== []) {
            $current = array_pop($stack);
            $key = $current->getName();
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            foreach ($current->getTraits() as $trait) {
                if ($trait->getName() === InteractsWithSockets::class) {
                    foreach ($trait->getProperties() as $traitProperty) {
                        $names[] = $traitProperty->getName();
                    }
                }

                $stack[] = $trait;
            }

            $parent = $current->getParentClass();
            if ($parent instanceof ReflectionClass) {
                $stack[] = $parent;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The TypeScript type of a public property as it goes over the wire;
     * null when a type it names has no TypeScript equivalent.
     */
    protected function typeToTsHint(?ReflectionType $reflectionType, string $declaringClass): ?string
    {
        if (! $reflectionType instanceof ReflectionType) {
            return 'unknown';
        }

        if ($reflectionType instanceof ReflectionUnionType) {
            $members = [];
            foreach ($reflectionType->getTypes() as $member) {
                $type = $this->typeToTsHint($member, $declaringClass);
                if ($type === null || $type === 'unknown') {
                    return $type;
                }

                $members[] = $type;
            }

            return implode(' | ', array_values(array_unique($members)));
        }

        if (! $reflectionType instanceof ReflectionNamedType) {
            // An intersection type.
            return null;
        }

        $name = $reflectionType->getName();
        $type = match ($name) {
            'int', 'float' => 'number',
            'string' => 'string',
            'bool' => 'boolean',
            'true', 'false', 'null' => $name,
            'array', 'iterable' => 'unknown[]',
            'mixed', 'object' => 'unknown',
            'self', 'static' => $this->classType($declaringClass),
            default => $reflectionType->isBuiltin() ? null : $this->classType($name),
        };

        if (in_array($type, [null, 'unknown', 'null'], true) || ! $reflectionType->allowsNull()) {
            return $type;
        }

        return $type.' | null';
    }
}
