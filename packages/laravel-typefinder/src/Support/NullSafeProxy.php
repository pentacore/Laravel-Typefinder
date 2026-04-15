<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Support;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Stringable;
use Traversable;

/**
 * Self-returning null-safe proxy used while evaluating a FormRequest's
 * `rules()` method (and similar introspection targets) when the real
 * request context — route, user, authenticated model — is unavailable.
 *
 * Every property access, method call, array access, cast to string,
 * iteration, and JSON-encode resolves to something harmless so that
 * expressions like `$this->route('user')->id` don't fatal at generation
 * time.
 *
 * Known limitations:
 *
 *   - `foreach` over the proxy yields an empty iteration. Code that builds
 *     rules inside `foreach ($this->user->permissions as $p) { … }` will
 *     produce an empty rules array — use the `#[TypefinderOverrides]`
 *     fallback to declare the shape manually.
 *   - `count($proxy)` returns `0` and `empty($proxy)` returns `false`
 *     (PHP objects are always truthy). Conditional logic that branches on
 *     emptiness may not behave the way source code intends, but since
 *     we're only collecting the *shape* the validator never actually runs.
 *   - Arithmetic with the proxy (`$proxy + 1`) will error — the proxy
 *     has no numeric cast.
 */
final class NullSafeProxy implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable, Stringable
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

    public function jsonSerialize(): null
    {
        return null;
    }
}
