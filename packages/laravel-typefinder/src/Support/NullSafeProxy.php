<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Support;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Stringable;
use Traversable;

/**
 * Self-returning null-safe proxy used while evaluating a FormRequest's
 * rules() method when the real request context (route, user, etc.) is
 * unavailable. Every property access, method call, array access, cast
 * to string, or iteration resolves to something harmless so that
 * expressions like `$this->route('user')->id` don't fatal.
 */
final class NullSafeProxy implements ArrayAccess, Countable, IteratorAggregate, Stringable
{
    public function __get(string $name): self
    {
        return $this;
    }

    public function __call(string $name, array $args): self
    {
        return $this;
    }

    public function __isset(string $name): bool
    {
        return false;
    }

    public function __toString(): string
    {
        return '';
    }

    public function offsetExists(mixed $offset): bool
    {
        return false;
    }

    public function offsetGet(mixed $offset): self
    {
        return $this;
    }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}

    public function count(): int
    {
        return 0;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator([]);
    }
}
