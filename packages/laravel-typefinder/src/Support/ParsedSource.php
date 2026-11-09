<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Support;

use PhpParser\Error;
use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Doc comments, method nodes and the namespace/`use` context of a PHP file,
 * read from disk with nikic/php-parser. Reading from disk (rather than
 * reflection) keeps docblocks fresh inside the long-lived `typefinder:watch`
 * process.
 */
final class ParsedSource
{
    /** @var array<string, array{hash: string, source: ?self}> */
    private static array $cache = [];

    /**
     * @param  array<string, array{context: NameContext, doc: ?string, methods: array<string, ClassMethod>}>  $classes
     */
    private function __construct(private readonly array $classes) {}

    /**
     * Null when the file is unreadable or does not parse. Cached per path and
     * re-parsed when the file's content hash changes.
     */
    public static function forFile(string $path): ?self
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;

        if ($contents === false) {
            unset(self::$cache[$path]);

            return null;
        }

        $hash = sha1($contents);

        if (isset(self::$cache[$path]) && self::$cache[$path]['hash'] === $hash) {
            return self::$cache[$path]['source'];
        }

        $source = self::parse($contents);
        self::$cache[$path] = ['hash' => $hash, 'source' => $source];

        return $source;
    }

    public function classDoc(string $classFqcn): ?string
    {
        return $this->classes[$classFqcn]['doc'] ?? null;
    }

    public function methodDoc(string $classFqcn, string $method): ?string
    {
        return $this->methodNode($classFqcn, $method)?->getDocComment()?->getText();
    }

    public function methodNode(string $classFqcn, string $method): ?ClassMethod
    {
        return $this->classes[$classFqcn]['methods'][strtolower($method)] ?? null;
    }

    /**
     * Resolve a docblock class identifier as PHP would inside $classFqcn's
     * namespace and imports. A leading backslash means fully qualified.
     */
    public function resolveName(string $classFqcn, string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        $context = $this->classes[$classFqcn]['context'] ?? null;

        if ($context === null) {
            return $name;
        }

        return $context->getResolvedClassName(new Name($name))->toString();
    }

    private static function parse(string $contents): ?self
    {
        try {
            $ast = (new ParserFactory)->createForHostVersion()->parse($contents);
        } catch (Error) {
            return null;
        }

        if ($ast === null) {
            return null;
        }

        $nameResolver = new NameResolver;
        $collector = new class($nameResolver) extends NodeVisitorAbstract
        {
            /** @var array<string, array{context: NameContext, doc: ?string, methods: array<string, ClassMethod>}> */
            public array $classes = [];

            public function __construct(private readonly NameResolver $nameResolver) {}

            public function enterNode(Node $node): null
            {
                if (! $node instanceof ClassLike || ! $node->name instanceof Identifier) {
                    return null;
                }

                $fqcn = ($node->namespacedName ?? $node->name)->toString();
                $methods = [];

                foreach ($node->getMethods() as $method) {
                    $methods[strtolower($method->name->toString())] = $method;
                }

                $this->classes[$fqcn] = [
                    // Snapshot: the live context mutates as traversal moves on.
                    'context' => clone $this->nameResolver->getNameContext(),
                    'doc' => $node->getDocComment()?->getText(),
                    'methods' => $methods,
                ];

                return null;
            }
        };

        $traverser = new NodeTraverser($nameResolver, $collector);
        $traverser->traverse($ast);

        return new self($collector->classes);
    }
}
