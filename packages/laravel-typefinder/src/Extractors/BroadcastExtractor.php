<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Extractors;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Pentacore\Typefinder\Attributes\TypefinderBroadcast;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Support\NullSafeProxy;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Component\Finder\Finder;
use Throwable;

class BroadcastExtractor
{
    /**
     * @return ?array{event_class: class-string, broadcast_name: string, channels: list<array{type: string, name: string}>, payload: array<string, string>}
     */
    public function extract(string $eventClass): ?array
    {
        $reflection = new ReflectionClass($eventClass);

        if (! $reflection->implementsInterface(ShouldBroadcast::class)) {
            return null;
        }

        if ($reflection->getAttributes(TypefinderIgnore::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
            return null;
        }

        $attribute = $this->getAttribute($reflection);
        $instance = $this->cheapInstance($reflection);

        $broadcastName = $attribute?->as
            ?? $this->tryCall($instance, 'broadcastAs')
            ?? $reflection->getShortName();

        if ($attribute?->channel !== null) {
            $channels = [['type' => $attribute->channelType ?? 'public', 'name' => $attribute->channel]];
        } else {
            $channels = $this->resolveChannels($instance);
            if ($channels === null) {
                throw new \RuntimeException("Could not resolve channels for {$eventClass}");
            }
        }

        $payload = $attribute !== null && $attribute->payload !== []
            ? $attribute->payload
            : $this->resolvePayload($instance, $reflection);

        return [
            'event_class' => $eventClass,
            'broadcast_name' => $broadcastName,
            'channels' => $channels,
            'payload' => $payload,
        ];
    }

    /**
     * @return list<array{event_class: class-string, broadcast_name: string, channels: list<array{type: string, name: string}>, payload: array<string, string>}>
     */
    public function extractFromDirectory(string $path, ?callable $onExtract = null, ?callable $onWarn = null): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $results = [];
        $finder = Finder::create()->files()->name('*.php')->in($path);

        foreach ($finder as $file) {
            $className = $this->resolveClassName($file->getRealPath());
            if ($className === null || ! class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);
            if (! $reflection->implementsInterface(ShouldBroadcast::class)) {
                continue;
            }

            if ($reflection->getAttributes(TypefinderIgnore::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                continue;
            }

            if ($onExtract !== null) {
                $onExtract($className);
            }

            try {
                $entry = $this->extract($className);
                if ($entry !== null) {
                    $results[] = $entry;
                }
            } catch (Throwable $throwable) {
                if ($onWarn !== null) {
                    $onWarn($className, $throwable);
                }
            }
        }

        return $results;
    }

    protected function getAttribute(ReflectionClass $reflection): ?TypefinderBroadcast
    {
        $attrs = $reflection->getAttributes(TypefinderBroadcast::class, ReflectionAttribute::IS_INSTANCEOF);

        return $attrs === [] ? null : $attrs[0]->newInstance();
    }

    /**
     * Instantiate without running the constructor. Events commonly accept
     * typed model instances in __construct (`public function __construct(Post $post)`)
     * which we can't fabricate; bypassing the ctor lets us still reflect
     * on broadcastOn()/broadcastWith()/public property types.
     */
    protected function cheapInstance(ReflectionClass $reflection): object
    {
        return $reflection->newInstanceWithoutConstructor();
    }

    protected function tryCall(object $instance, string $method): ?string
    {
        if (! method_exists($instance, $method)) {
            return null;
        }

        try {
            $value = $instance->{$method}();

            return is_string($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
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
     * @return array<string, string>
     */
    protected function resolvePayload(object $instance, ReflectionClass $reflection): array
    {
        if (method_exists($instance, 'broadcastWith')) {
            try {
                $with = $instance->broadcastWith();
                if (is_array($with)) {
                    return array_map(
                        fn (mixed $v): string => is_string($v) ? $v : 'unknown',
                        $with,
                    );
                }
            } catch (Throwable) {
                // fall through to property inspection
            }
        }

        $payload = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $payload[$property->getName()] = $this->typeToTsHint($property->getType());
        }

        return $payload;
    }

    protected function typeToTsHint(?\ReflectionType $type): string
    {
        if (! $type instanceof ReflectionNamedType) {
            return 'unknown';
        }

        return match ($type->getName()) {
            'int', 'float' => 'number',
            'string' => 'string',
            'bool' => 'boolean',
            'array' => 'unknown[]',
            default => $type->getName(),
        };
    }

    protected function resolveClassName(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if (! preg_match('/namespace\s+(.+?);/', (string) $contents, $ns)) {
            return null;
        }

        if (! preg_match('/class\s+(\w+)/', (string) $contents, $cls)) {
            return null;
        }

        return $ns[1].'\\'.$cls[1];
    }
}
