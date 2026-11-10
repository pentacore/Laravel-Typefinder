<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Resolvers;

use Closure;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Throwable;

/**
 * Where a PHPDoc type is read: the resource being generated, how class names
 * resolve in the declaring file, and the type aliases visible there.
 */
final readonly class PhpDocScope
{
    /**
     * @param  class-string|string  $selfFqcn  what `static` / `self` / `$this` mean (the resource being generated, also for inherited docs)
     * @param  class-string|string  $resourceFqcn  named in warnings
     * @param  Closure(string): string  $nameResolver  resolves a docblock identifier in the declaring file
     * @param  array<string, array{type: TypeNode, scope: ?PhpDocScope}>  $aliases  aliases defined here, by local name; a null scope
     *                                                                              means the alias is defined in this scope
     * @param  null|Closure(string): ?array{type: TypeNode, scope: ?PhpDocScope}  $importedAlias  looks up an imported alias by local
     *                                                                                            name, on demand (one at a time)
     */
    public function __construct(
        public string $selfFqcn,
        public string $resourceFqcn,
        public Closure $nameResolver,
        public array $aliases = [],
        private ?Closure $importedAlias = null,
    ) {}

    /**
     * The alias visible here under $name: a local `@phpstan-type` first,
     * else an imported one.
     *
     * @return ?array{type: TypeNode, scope: ?PhpDocScope}
     */
    public function alias(string $name): ?array
    {
        return $this->aliases[$name] ?? ($this->importedAlias instanceof Closure ? ($this->importedAlias)($name) : null);
    }

    /**
     * A docblock identifier as a class name without a leading backslash, or
     * null when the name resolver rejects it. Does not check that it exists.
     */
    public function resolveName(string $name): ?string
    {
        try {
            return ltrim(($this->nameResolver)($name), '\\');
        } catch (Throwable) {
            return null;
        }
    }
}
