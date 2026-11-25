<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Resolvers;

use Pentacore\Typefinder\Support\GeneratedClasses;
use Pentacore\Typefinder\Support\ParsedSource;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use ReflectionClass;
use Throwable;

/**
 * The class-level `@property` / `@property-read` tags of a model, as the type
 * trees {@see PhpDocTypeMapper} builds for resources. A model column whose
 * cast only says "array" or "JSON" takes its type from here.
 */
final class ModelPropertyDocReader
{
    private const array TAGS = ['@property', '@property-read', '@phpstan-property', '@phpstan-property-read', '@psalm-property', '@psalm-property-read'];

    private readonly Lexer $lexer;

    private readonly PhpDocParser $phpDocParser;

    private ?PhpDocTypeMapper $phpDocTypeMapper = null;

    public function __construct(private readonly ?GeneratedClasses $classes = null)
    {
        $parserConfig = new ParserConfig([]);
        $constExprParser = new ConstExprParser($parserConfig);
        $this->lexer = new Lexer($parserConfig);
        $this->phpDocParser = new PhpDocParser($parserConfig, new TypeParser($parserConfig, $constExprParser), $constExprParser);
    }

    /**
     * @param  ReflectionClass<object>  $model
     * @return array<string, array<string, mixed>> type tree by property name
     */
    public function read(ReflectionClass $model): array
    {
        $doc = $model->getDocComment();
        $file = $model->getFileName();
        if ($doc === false || $file === false) {
            return [];
        }

        try {
            $node = $this->phpDocParser->parse(new TokenIterator($this->lexer->tokenize($doc)));
        } catch (Throwable) {
            return [];
        }

        $source = ParsedSource::forFile($file);
        $class = $model->getName();
        $scope = new PhpDocScope(
            $class,
            $class,
            static fn (string $name): string => $source instanceof ParsedSource ? $source->resolveName($class, $name) : ltrim($name, '\\'),
        );

        $types = [];
        foreach ($this->propertyTags($node) as $tag) {
            $name = ltrim($tag->propertyName, '$');
            // Without a warning callback: a part the PHPDoc cannot express
            // is `unknown`, as the cast alone would have it.
            $types[$name] = $this->mapper()->map($tag->type, $scope, $name, '@property');
        }

        return $types;
    }

    /**
     * @return list<PropertyTagValueNode>
     */
    private function propertyTags(PhpDocNode $node): array
    {
        $tags = [];
        foreach (self::TAGS as $tagName) {
            foreach ($node->getTagsByName($tagName) as $tag) {
                if ($tag->value instanceof PropertyTagValueNode) {
                    $tags[] = $tag->value;
                }
            }
        }

        return $tags;
    }

    private function mapper(): PhpDocTypeMapper
    {
        return $this->phpDocTypeMapper ??= new PhpDocTypeMapper($this->classes ?? new GeneratedClasses);
    }
}
