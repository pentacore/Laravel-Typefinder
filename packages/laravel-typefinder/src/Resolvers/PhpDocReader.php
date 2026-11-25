<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Resolvers;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Pentacore\Typefinder\Support\ParsedSource;
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
use Throwable;

/**
 * The doc-reading half of {@see PhpDocShapeResolver}: parses PHPDoc, picks
 * the winning `@*return` tag, builds the scope a doc is read in (the
 * declaring file's names, `@phpstan-type` aliases and `@phpstan-import-type`
 * imports) and decides whether a return type counts as a shape. Knows
 * nothing about resources, delegation or composition.
 */
final readonly class PhpDocReader
{
    /** In precedence order (PHPStan's own). */
    public const array RETURN_TAGS = ['@phpstan-return', '@psalm-return', '@return'];

    private const array ALIAS_TAGS = ['@phpstan-type', '@psalm-type'];

    private const array IMPORT_TAGS = ['@phpstan-import-type', '@psalm-import-type'];

    private Lexer $lexer;

    private PhpDocParser $phpDocParser;

    /**
     * @param  Closure(string): void  $onWarn  told about return tags that do not parse
     */
    public function __construct(private Closure $onWarn)
    {
        $parserConfig = new ParserConfig([]);
        $constExprParser = new ConstExprParser($parserConfig);
        $this->lexer = new Lexer($parserConfig);
        $this->phpDocParser = new PhpDocParser($parserConfig, new TypeParser($parserConfig, $constExprParser), $constExprParser);
    }

    public function parseDoc(?string $doc): ?PhpDocNode
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

    /**
     * Whether the doc has any return tag (valid or not); without one, the
     * doc says nothing about the return type (none, or only inheritDoc).
     */
    public function hasReturnTag(?PhpDocNode $doc): bool
    {
        return $doc instanceof PhpDocNode && array_any(
            self::RETURN_TAGS,
            static fn (string $tag): bool => $doc->getTagsByName($tag) !== [],
        );
    }

    /**
     * The type of the winning return tag (first valid one in precedence
     * order).
     */
    public function returnType(?PhpDocNode $doc): ?TypeNode
    {
        foreach (self::RETURN_TAGS as $tag) {
            $values = $doc?->getReturnTagValues($tag) ?? [];

            if ($values !== []) {
                return $values[0]->type;
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
    public function warnUnparsable(PhpDocNode $doc, string $resource, array $tags): void
    {
        foreach ($tags as $tag) {
            foreach ($doc->getTagsByName($tag) as $node) {
                if ($node->value instanceof InvalidTagValueNode && str_contains($node->value->value, 'array{')) {
                    ($this->onWarn)(sprintf(
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
    public function shapeOf(TypeNode $type, PhpDocScope $scope): ?array
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
    public function scopeFor(string $class, string $resource, array &$scopes): ?PhpDocScope
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
     * Resolve one imported alias in the class, interface or trait it is
     * imported from. A missing class or alias yields null: the local name
     * then fails to resolve in the mapper and becomes `unknown` with a
     * warning.
     *
     * @param  PhpDocScope  $names  the importing file's names
     * @param  array<string, ?PhpDocScope>  $scopes
     * @return ?array{type: TypeNode, scope: PhpDocScope}
     */
    private function importAlias(TypeAliasImportTagValueNode $import, PhpDocScope $names, string $resource, array &$scopes): ?array
    {
        // An identifier, or a name node; either prints as the class name.
        $from = $names->resolveName((string) $import->importedFrom);

        try {
            if ($from === null || (! class_exists($from) && ! interface_exists($from) && ! trait_exists($from))) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $source = $this->scopeFor($from, $resource, $scopes);
        $alias = $source?->alias($import->importedAlias);

        return $alias === null ? null : ['type' => $alias['type'], 'scope' => $alias['scope'] ?? $source];
    }

    private function isObjectShape(TypeNode $type): bool
    {
        return $type instanceof ObjectShapeNode
            || ($type instanceof ArrayShapeNode && ! PhpDocTypeMapper::isListShape($type));
    }
}
