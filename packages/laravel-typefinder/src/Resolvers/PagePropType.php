<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Resolvers;

use Closure;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Support\GeneratedClasses;
use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\OffsetAccessTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Throwable;

/**
 * The TypeScript type of a `#[TypefinderPage]` prop (#247). A prop is
 * written as TypeScript or a class-string, but a PHPDoc type (`array{id:
 * int}`, `list<User>`, `?string`, `array<string, int>`) is accepted too: it
 * is converted through {@see PhpDocTypeMapper}, with class names resolved
 * through the controller file's imports. Model, enum and resource leaves
 * stay fully qualified, so the renderer resolves and imports them as it does
 * a class-string prop.
 *
 * A type is converted only when it uses PHPDoc-only syntax (an array shape,
 * `?T`, `int`, `list<…>`, …) and nothing that is TypeScript-only (`number`,
 * `Record<…>`, a helper type); anything else is TypeScript and kept as
 * written. A type that mixes both, is empty or has unbalanced brackets
 * becomes `unknown` with a warning, so `--strict` fails on it.
 */
final class PagePropType
{
    /** PHPDoc keywords that are no TypeScript type (any hyphenated keyword is none either). */
    private const array PHPDOC_KEYWORDS = [
        'int', 'integer', 'float', 'double', 'bool', 'mixed', 'array', 'list', 'iterable', 'scalar', 'numeric',
        'callable', 'resource', 'closure',
    ];

    /** Types both languages spell the same. */
    private const array SHARED_KEYWORDS = ['string', 'null', 'true', 'false', 'boolean', 'object', 'void', 'never'];

    private readonly PhpDocTypeMapper $phpDocTypeMapper;

    private readonly PhpDocReader $phpDocReader;

    private readonly TypeScriptRenderer $typeScriptRenderer;

    /** @var Closure(string): void */
    private readonly Closure $onWarn;

    /** @var array<string, ?PhpDocScope> scopes by controller, built once each */
    private array $scopes = [];

    /**
     * @param  null|callable(string): void  $onWarn
     */
    public function __construct(GeneratedClasses $classes, ?callable $onWarn = null)
    {
        $this->onWarn = $onWarn === null ? static function (string $message): void {} : $onWarn(...);
        $this->phpDocTypeMapper = new PhpDocTypeMapper($classes, $this->onWarn);
        $this->phpDocReader = new PhpDocReader($this->onWarn);
        $this->typeScriptRenderer = new TypeScriptRenderer;
    }

    /**
     * @param  class-string  $controller
     */
    public function resolve(string $type, string $controller, string $method, string $prop): string
    {
        $origin = sprintf('%s::%s() #[TypefinderPage] prop "%s"', $controller, $method, $prop);
        $trimmed = trim($type);

        if ($trimmed === '') {
            return $this->unknown($origin, 'is empty');
        }

        $node = $this->parse($this->fullyQualified($trimmed));
        $scope = $node instanceof TypeNode ? $this->scope($controller) : null;

        if (! $node instanceof TypeNode || ! $scope instanceof PhpDocScope) {
            return $this->isBalanced($trimmed)
                ? $type
                : $this->unknown($origin, sprintf('"%s" is no valid type (unbalanced brackets or quotes)', $trimmed));
        }

        [$phpDoc, $typeScript] = $this->syntaxOf($node, $scope);

        if (! $phpDoc) {
            return $type;
        }

        if ($typeScript) {
            return $this->unknown($origin, sprintf('"%s" mixes PHPDoc and TypeScript syntax; write it in one of them', $trimmed));
        }

        return $this->typeScriptRenderer->renderTypeNodeWithClassNames(
            $this->phpDocTypeMapper->map($node, $scope, $prop, $method, $origin),
        );
    }

    /**
     * Whether the type uses PHPDoc-only syntax and whether it uses
     * TypeScript-only names. Class names (short or qualified) and the
     * keywords both languages share count as neither.
     *
     * @return array{bool, bool}
     */
    private function syntaxOf(TypeNode $node, PhpDocScope $scope): array
    {
        $visitor = new class(fn (string $name): bool => $this->isClass($name, $scope)) extends AbstractNodeVisitor
        {
            public bool $phpDoc = false;

            public bool $typeScript = false;

            /** @var array<int, true> object ids of shape keys, which are no types */
            private array $keys = [];

            /** @param  Closure(string): bool  $isClass */
            public function __construct(private readonly Closure $isClass) {}

            public function enterNode(Node $node): null
            {
                if (($node instanceof ArrayShapeItemNode || $node instanceof ObjectShapeItemNode) && $node->keyName instanceof Node) {
                    $this->keys[spl_object_id($node->keyName)] = true;
                }

                match (true) {
                    isset($this->keys[spl_object_id($node)]) => null,
                    $node instanceof ArrayShapeNode,
                    $node instanceof ObjectShapeNode,
                    $node instanceof NullableTypeNode,
                    $node instanceof CallableTypeNode,
                    $node instanceof ConditionalTypeNode,
                    $node instanceof ConditionalTypeForParameterNode => $this->phpDoc = true,
                    $node instanceof OffsetAccessTypeNode => $this->typeScript = true,
                    // Also a generic's base: `list<…>` is PHPDoc, `Record<…>` TypeScript.
                    $node instanceof IdentifierTypeNode => $this->identifier($node->name),
                    default => null,
                };

                return null;
            }

            private function identifier(string $name): void
            {
                if (in_array($name, PagePropType::phpDocKeywords(), true) || str_contains($name, '-')) {
                    $this->phpDoc = true;
                } elseif (! in_array($name, PagePropType::sharedKeywords(), true) && ! ($this->isClass)($name)) {
                    $this->typeScript = true;
                }
            }
        };

        new NodeTraverser([$visitor])->traverse([$node]);

        return [$visitor->phpDoc, $visitor->typeScript];
    }

    /**
     * @return list<string>
     */
    public static function phpDocKeywords(): array
    {
        return self::PHPDOC_KEYWORDS;
    }

    /**
     * @return list<string>
     */
    public static function sharedKeywords(): array
    {
        return self::SHARED_KEYWORDS;
    }

    /**
     * Whether a type name is a class (or interface) in the controller's file.
     */
    private function isClass(string $name, PhpDocScope $scope): bool
    {
        $fqcn = $scope->resolveName($name);

        try {
            return $fqcn !== null && (class_exists($fqcn) || interface_exists($fqcn));
        } catch (Throwable) {
            // A class file that fails to load is no class here either.
            return false;
        }
    }

    /**
     * @param  class-string  $controller
     */
    private function scope(string $controller): ?PhpDocScope
    {
        return $this->phpDocReader->scopeFor($controller, $controller, $this->scopes);
    }

    private function unknown(string $origin, string $problem): string
    {
        ($this->onWarn)(sprintf('%s: %s — emitted as unknown', $origin, $problem));

        return 'unknown';
    }

    /**
     * The text with a leading backslash on every qualified class name that
     * lacks one. A prop type is built from class-strings (`User::class` is
     * `App\Models\User`), which are fully qualified, while PHPDoc reads a
     * qualified name relative to the file's namespace.
     */
    private function fullyQualified(string $type): string
    {
        return (string) preg_replace_callback(
            '/(?<![\\\\\w$])[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)+/',
            static function (array $match): string {
                try {
                    $exists = class_exists($match[0]) || interface_exists($match[0]);
                } catch (Throwable) {
                    // A class file that fails to load is no class here either.
                    $exists = false;
                }

                return $exists ? '\\'.$match[0] : $match[0];
            },
            $type,
        );
    }

    /**
     * The whole text as one PHPDoc type, or null when it is none.
     */
    private function parse(string $type): ?TypeNode
    {
        $config = new ParserConfig([]);
        $tokens = new TokenIterator(new Lexer($config)->tokenize($type));

        try {
            $node = new TypeParser($config, new ConstExprParser($config))->parse($tokens);
        } catch (Throwable) {
            return null;
        }

        return $tokens->currentTokenType() === Lexer::TOKEN_END ? $node : null;
    }

    /**
     * Whether the brackets and quotes of a TypeScript type pair up.
     */
    private function isBalanced(string $type): bool
    {
        $pairs = [')' => '(', ']' => '[', '}' => '{', '>' => '<'];
        $stack = [];
        $quote = null;
        $length = strlen($type);

        for ($i = 0; $i < $length; $i++) {
            $char = $type[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if (in_array($char, ["'", '"', '`'], true)) {
                $quote = $char;
            } elseif ($char === '=' && ($type[$i + 1] ?? '') === '>') {
                $i++; // an arrow function's `=>` closes no `<`
            } elseif (in_array($char, $pairs, true)) {
                $stack[] = $char;
            } elseif (isset($pairs[$char]) && array_pop($stack) !== $pairs[$char]) {
                return false;
            }
        }

        return $quote === null && $stack === [];
    }
}
