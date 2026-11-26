<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Rector\Support;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * The expressions a method returns, with the arms of a returned ternary or
 * `match` as returns of their own. Returns of nested closures, arrow
 * functions, functions and anonymous classes are not the method's.
 */
final class ReturnedExprs
{
    /** @return list<Expr> */
    public static function of(ClassMethod $method): array
    {
        $visitor = new class extends NodeVisitorAbstract
        {
            /** @var list<Expr> */
            public array $exprs = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Closure || $node instanceof ArrowFunction || $node instanceof Class_ || $node instanceof Function_) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Return_ && $node->expr instanceof Expr) {
                    $this->add($node->expr);
                }

                return null;
            }

            private function add(Expr $expr): void
            {
                if ($expr instanceof Ternary) {
                    $this->add($expr->if ?? $expr->cond);
                    $this->add($expr->else);

                    return;
                }

                if ($expr instanceof Match_) {
                    foreach ($expr->arms as $arm) {
                        $this->add($arm->body);
                    }

                    return;
                }

                $this->exprs[] = $expr;
            }
        };

        new NodeTraverser($visitor)->traverse($method->stmts ?? []);

        return $visitor->exprs;
    }
}
