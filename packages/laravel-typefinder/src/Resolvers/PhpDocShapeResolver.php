<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Resolvers;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonSerializable;
use Pentacore\Typefinder\Support\GeneratedClasses;
use Pentacore\Typefinder\Support\ParsedSource;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\PhpDocParser\Ast\PhpDoc\InvalidTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypeAliasImportTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Reads the PHPDoc return shape of a resource's `toArray()` from disk and
 * maps it to a type tree. Null means "no shape here" and the caller falls
 * through to the next tier.
 */
final readonly class PhpDocShapeResolver
{
    /** In precedence order (PHPStan's own). */
    private const array RETURN_TAGS = ['@phpstan-return', '@psalm-return', '@return'];

    private const array ALIAS_TAGS = ['@phpstan-type', '@psalm-type'];

    private const array IMPORT_TAGS = ['@phpstan-import-type', '@psalm-import-type'];

    private PhpDocTypeMapper $phpDocTypeMapper;

    private ?Closure $onWarn;

    private Lexer $lexer;

    private PhpDocParser $phpDocParser;

    /**
     * @param  null|callable(string): void  $onWarn
     */
    public function __construct(GeneratedClasses $classes, ?callable $onWarn = null)
    {
        $this->onWarn = $onWarn === null ? null : $onWarn(...);
        $this->phpDocTypeMapper = new PhpDocTypeMapper($classes, $onWarn);

        $parserConfig = new ParserConfig([]);
        $constExprParser = new ConstExprParser($parserConfig);
        $this->lexer = new Lexer($parserConfig);
        $this->phpDocParser = new PhpDocParser($parserConfig, new TypeParser($parserConfig, $constExprParser), $constExprParser);
    }

    /**
     * @param  ReflectionClass<object>  $resource
     * @return ?array<string, mixed> an `object` node
     */
    public function resolve(ReflectionClass $resource): ?array
    {
        if ($resource->isAbstract() || ! $resource->isSubclassOf(JsonResource::class)) {
            return null;
        }

        $found = $this->findReturnType($resource);

        if ($found === null) {
            return null;
        }

        [$type, $declaring] = $found;
        $scopes = [];
        $scope = $this->scopeFor($declaring, $resource->getName(), $scopes);
        $shape = $scope instanceof PhpDocScope ? $this->shapeOf($type, $scope) : null;

        return $shape === null ? null : $this->phpDocTypeMapper->map($shape[0], $shape[1], '');
    }

    /**
     * The winning `@*return` type of `toArray()` and the class or trait whose
     * doc declares it, walking up past docs without a return tag (no doc, or
     * only inheritDoc) until `JsonResource`.
     *
     * @param  ReflectionClass<object>  $resource
     * @return ?array{TypeNode, class-string}
     */
    private function findReturnType(ReflectionClass $resource): ?array
    {
        $class = new ReflectionMethod($resource->getName(), 'toArray')->getDeclaringClass();

        while ($class->getName() !== JsonResource::class) {
            // A trait-provided toArray() reports the using class as declaring
            // it, but the method (and its doc) lives in the trait's file.
            $owner = $this->toArraySource($class) ?? $this->traitToArraySource($class);

            if ($owner === null) {
                return null;
            }

            [$ownerName, $source] = $owner;
            $doc = $this->parseDoc($source->methodDoc($ownerName, 'toArray'));
            $hasReturnTag = $doc instanceof PhpDocNode && array_filter(
                self::RETURN_TAGS,
                static fn (string $tag): bool => $doc->getTagsByName($tag) !== [],
            ) !== [];

            if (! $hasReturnTag) {
                $parent = $class->getParentClass();

                if ($parent === false) {
                    return null;
                }

                $class = new ReflectionMethod($parent->getName(), 'toArray')->getDeclaringClass();

                continue;
            }

            foreach (self::RETURN_TAGS as $rank => $tag) {
                $values = $doc->getReturnTagValues($tag);

                if ($values !== []) {
                    // A broken higher-precedence tag still deserves its warning.
                    $this->warnUnparsable($doc, $resource->getName(), array_slice(self::RETURN_TAGS, 0, $rank));

                    return [$values[0]->type, $ownerName];
                }
            }

            $this->warnUnparsable($doc, $resource->getName(), self::RETURN_TAGS);

            return null;
        }

        return null;
    }

    /**
     * The class itself and its parsed file, when that file declares `toArray()`.
     *
     * @param  ReflectionClass<object>  $class
     * @return ?array{class-string, ParsedSource}
     */
    private function toArraySource(ReflectionClass $class): ?array
    {
        $file = $class->getFileName();
        $source = $file === false ? null : ParsedSource::forFile($file);

        return $source?->methodNode($class->getName(), 'toArray') instanceof ClassMethod
            ? [$class->getName(), $source]
            : null;
    }

    /**
     * The first trait (in `use` order, depth-first through traits used by
     * traits) whose own file declares `toArray()`.
     *
     * @param  ReflectionClass<object>  $class
     * @return ?array{class-string, ParsedSource}
     */
    private function traitToArraySource(ReflectionClass $class): ?array
    {
        foreach ($class->getTraits() as $trait) {
            $found = $this->toArraySource($trait) ?? $this->traitToArraySource($trait);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Warn once when one of $tags failed to parse and its text clearly meant
     * a shape.
     *
     * @param  list<string>  $tags
     */
    private function warnUnparsable(PhpDocNode $doc, string $resource, array $tags): void
    {
        foreach ($tags as $tag) {
            foreach ($doc->getTagsByName($tag) as $node) {
                if ($node->value instanceof InvalidTagValueNode && str_contains($node->value->value, 'array{')) {
                    $this->warn(sprintf(
                        'Could not parse %s on %s::toArray(): %s',
                        $tag,
                        $resource,
                        $node->value->exception?->getMessage() ?? $node->value->value,
                    ));

                    return;
                }
            }
        }
    }

    /**
     * Apply "does it count as a shape" to the (alias-expanded) return type:
     * a keyed `array{…}` / `object{…}`, alone or in a union with only
     * `array`, `Arrayable` and `JsonSerializable`.
     *
     * @return ?array{TypeNode, PhpDocScope}
     */
    private function shapeOf(TypeNode $type, PhpDocScope $scope): ?array
    {
        [$type, $scope] = $this->expandAlias($type, $scope);

        if (! $type instanceof UnionTypeNode) {
            return $this->isObjectShape($type) ? [$type, $scope] : null;
        }

        $shape = null;

        foreach ($type->types as $member) {
            [$member, $memberScope] = $this->expandAlias($member, $scope);

            if ($member instanceof ArrayShapeNode || $member instanceof ObjectShapeNode) {
                if ($shape !== null) {
                    return null;
                }

                $shape = [$member, $memberScope];
            } elseif (! $this->isLaravelReturnMember($member, $memberScope)) {
                return null;
            }
        }

        return $shape !== null && $this->isObjectShape($shape[0]) ? $shape : null;
    }

    /**
     * @return array{TypeNode, PhpDocScope}
     */
    private function expandAlias(TypeNode $type, PhpDocScope $scope): array
    {
        $seen = [];

        while ($type instanceof IdentifierTypeNode && ($alias = $scope->alias($type->name)) !== null) {
            if (isset($seen[spl_object_id($alias['type'])])) {
                break;
            }

            $seen[spl_object_id($alias['type'])] = true;
            $type = $alias['type'];
            $scope = $alias['scope'] ?? $scope;
        }

        return [$type, $scope];
    }

    private function isLaravelReturnMember(TypeNode $type, PhpDocScope $scope): bool
    {
        if (! $type instanceof IdentifierTypeNode) {
            return false;
        }

        if (strtolower($type->name) === 'array') {
            return true;
        }

        $fqcn = $scope->resolveName($type->name);

        return $fqcn === Arrayable::class || $fqcn === JsonSerializable::class;
    }

    /**
     * Scope for a doc declared in $class: its file's names and its class-level
     * aliases. Imported aliases are looked up on demand, one at a time, and
     * memoised, so classes that import from each other cost one lookup per
     * alias used rather than every scope they could reach.
     *
     * @param  array<string, ?PhpDocScope>  $scopes  scopes built during this resolve(), by class; one
     *                                               scope (and one parse of its doc) per class keeps alias
     *                                               type nodes stable for the mapper's recursion guard
     */
    private function scopeFor(string $class, string $resource, array &$scopes): ?PhpDocScope
    {
        if (array_key_exists($class, $scopes)) {
            return $scopes[$class];
        }

        $file = new ReflectionClass($class)->getFileName();
        $source = $file === false ? null : ParsedSource::forFile($file);

        if (! $source instanceof ParsedSource) {
            return $scopes[$class] = null;
        }

        $nameResolver = static fn (string $name): string => $source->resolveName($class, $name);
        $doc = $this->parseDoc($source->classDoc($class));
        $aliases = [];
        $imports = [];

        if ($doc instanceof PhpDocNode) {
            foreach (self::IMPORT_TAGS as $tag) {
                foreach ($doc->getTypeAliasImportTagValues($tag) as $import) {
                    $imports[$import->importedAs ?? $import->importedAlias] = $import;
                }
            }

            foreach (self::ALIAS_TAGS as $tag) {
                foreach ($doc->getTypeAliasTagValues($tag) as $alias) {
                    $aliases[$alias->alias] = ['type' => $alias->type, 'scope' => null];
                }
            }
        }

        $names = new PhpDocScope($resource, $resource, $nameResolver);
        $resolved = [];
        $importedAlias = function (string $name) use ($imports, $names, $resource, &$scopes, &$resolved): ?array {
            if (! isset($imports[$name])) {
                return null;
            }

            if (array_key_exists($name, $resolved)) {
                return $resolved[$name];
            }

            // Null while in progress: an import chain that leads back to this
            // (class, alias) pair is a cycle and resolves to nothing.
            $resolved[$name] = null;

            return $resolved[$name] = $this->importAlias($imports[$name], $names, $resource, $scopes);
        };

        return $scopes[$class] = new PhpDocScope($resource, $resource, $nameResolver, $aliases, $importedAlias);
    }

    /**
     * Resolve one imported alias in the class it is imported from. A missing
     * class or alias yields null: the local name then fails to resolve in the
     * mapper and becomes `unknown` with a warning.
     *
     * @param  PhpDocScope  $names  the importing file's names
     * @param  array<string, ?PhpDocScope>  $scopes
     * @return ?array{type: TypeNode, scope: PhpDocScope}
     */
    private function importAlias(TypeAliasImportTagValueNode $import, PhpDocScope $names, string $resource, array &$scopes): ?array
    {
        if (! $import->importedFrom instanceof IdentifierTypeNode) {
            return null;
        }

        $from = $names->resolveName($import->importedFrom->name);

        try {
            if ($from === null || (! class_exists($from) && ! interface_exists($from))) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $source = $this->scopeFor($from, $resource, $scopes);
        $alias = $source?->alias($import->importedAlias);

        return $alias === null ? null : ['type' => $alias['type'], 'scope' => $alias['scope'] ?? $source];
    }

    private function parseDoc(?string $doc): ?PhpDocNode
    {
        if ($doc === null) {
            return null;
        }

        try {
            return $this->phpDocParser->parse(new TokenIterator($this->lexer->tokenize($doc)));
        } catch (Throwable) {
            return null;
        }
    }

    private function warn(string $message): void
    {
        if ($this->onWarn instanceof Closure) {
            ($this->onWarn)($message);
        }
    }

    private function isObjectShape(TypeNode $type): bool
    {
        return $type instanceof ObjectShapeNode
            || ($type instanceof ArrayShapeNode && ! PhpDocTypeMapper::isListShape($type));
    }
}
