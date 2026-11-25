<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\If_;
use PhpParser\NodeFinder;
use ReflectionClass;

/**
 * Finds the request state a form request's `rules()` branches on: the
 * `$this->…` and `request()` / `auth()` reads in the condition of an `if`,
 * a ternary or a `match`. Typefinder calls `rules()` with no route, user or
 * input, so such a `rules()` is typed from one branch only.
 *
 * Reads only the `rules()` method itself. Never evaluates anything.
 */
final class RequestStateBranches
{
    private const array STATE_FUNCTIONS = ['request', 'auth', 'route'];

    /**
     * @param  ReflectionClass<object>  $request
     * @return list<string> the reads, as `$this->route()` / `request()`
     */
    public static function in(ReflectionClass $request): array
    {
        if (! $request->hasMethod('rules')) {
            return [];
        }

        $method = QueryRuleKeys::methodNode($request->getMethod('rules'));

        if (! $method instanceof ClassMethod || $method->stmts === null) {
            return [];
        }

        $finder = new NodeFinder;
        $conditions = [];

        foreach ($finder->find($method->stmts, static fn (Node $node): bool => $node instanceof If_ || $node instanceof ElseIf_ || $node instanceof Ternary || $node instanceof Match_) as $branch) {
            /** @var If_|ElseIf_|Ternary|Match_ $branch */
            $conditions[] = $branch->cond;

            if ($branch instanceof Match_) {
                foreach ($branch->arms as $arm) {
                    array_push($conditions, ...($arm->conds ?? []));
                }
            }
        }

        $reads = [];

        foreach ($conditions as $condition) {
            foreach ($finder->find($condition, self::isStateRead(...)) as $read) {
                $reads[] = self::describe($read);
            }
        }

        return array_values(array_unique($reads));
    }

    private static function isStateRead(Node $node): bool
    {
        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch) {
            return $node->var instanceof Variable && $node->var->name === 'this';
        }

        return $node instanceof FuncCall
            && $node->name instanceof Name
            && in_array($node->name->toLowerString(), self::STATE_FUNCTIONS, true);
    }

    private static function describe(Expr $read): string
    {
        if ($read instanceof FuncCall) {
            return $read->name instanceof Name ? $read->name->toString().'()' : 'a function call';
        }

        /** @var MethodCall|NullsafeMethodCall|PropertyFetch|NullsafePropertyFetch $read */
        $name = $read->name instanceof Identifier ? $read->name->toString() : '…';
        $isCall = $read instanceof MethodCall || $read instanceof NullsafeMethodCall;

        return '$this->'.$name.($isCall ? '()' : '');
    }
}
