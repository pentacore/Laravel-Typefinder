<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Rector\Support;

use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;

/**
 * The names in a PHPDoc type that may be class names: identifiers (not
 * array or object shape keys) and the class of `Foo::BAR`, each with the
 * token index of the name.
 */
final class DocClassNames
{
    /** @return list<array{name: string, index: int}> */
    public static function in(TypeNode $type): array
    {
        $visitor = new class extends AbstractNodeVisitor
        {
            /** @var list<array{name: string, index: int}> */
            public array $names = [];

            /** @var array<int, true> */
            private array $keys = [];

            public function enterNode(Node $node): ?Node
            {
                if (($node instanceof ArrayShapeItemNode || $node instanceof ObjectShapeItemNode) && $node->keyName instanceof Node) {
                    $this->keys[spl_object_id($node->keyName)] = true;
                }

                if ($node instanceof IdentifierTypeNode && ! isset($this->keys[spl_object_id($node)])) {
                    $this->names[] = ['name' => $node->name, 'index' => DocTokens::startOf($node)];
                }

                if ($node instanceof ConstTypeNode && $node->constExpr instanceof ConstFetchNode && $node->constExpr->className !== '') {
                    $this->names[] = ['name' => $node->constExpr->className, 'index' => DocTokens::startOf($node)];
                }

                return null;
            }
        };

        new NodeTraverser([$visitor])->traverse([$type]);

        return $visitor->names;
    }
}
