<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Resolvers;

use Closure;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;

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
     * @param  array<string, array{type: TypeNode, scope: ?PhpDocScope}>  $aliases  `@phpstan-type` aliases and imports by local name;
     *                                                                              a null scope means the alias is defined in this scope
     */
    public function __construct(
        public string $selfFqcn,
        public string $resourceFqcn,
        public Closure $nameResolver,
        public array $aliases = [],
    ) {}
}
