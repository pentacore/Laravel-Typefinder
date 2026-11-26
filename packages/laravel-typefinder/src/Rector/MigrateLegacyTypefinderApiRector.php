<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Rector;

use Pentacore\Typefinder\Attributes\TypefinderCast;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;
use Pentacore\Typefinder\Attributes\TypefinderWriteShape;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TraitUse;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Moves the APIs Typefinder removed in v2 and v3 to the attributes that
 * replaced them:
 *  - `use HasTypeOverrides` + `typeOverrides()` → `#[TypefinderOverrides]`;
 *  - `use HasWriteShapeContract` + `typefinderServerFilled()`,
 *    `typefinderRespectMassAssignment()`, `typefinderImmutableOnUpdate()`
 *    → `#[TypefinderWriteShape]`;
 *  - `implements HasTypeDefinition` + `typeDefinition()` →
 *    `#[TypefinderCast]`.
 * A method moves only when it returns one constant expression (what an
 * attribute argument can hold); otherwise it is left for you. The removed
 * trait and interface are dropped either way: they no longer exist, so a
 * class that names them does not load.
 */
final class MigrateLegacyTypefinderApiRector extends AbstractRector
{
    private const string OVERRIDES_TRAIT = 'Pentacore\\Typefinder\\Concerns\\HasTypeOverrides';

    private const string WRITE_SHAPE_TRAIT = 'Pentacore\\Typefinder\\Concerns\\HasWriteShapeContract';

    private const string TYPE_DEFINITION = 'Pentacore\\Typefinder\\Contracts\\HasTypeDefinition';

    /** Legacy write-shape method => `#[TypefinderWriteShape]` argument. */
    private const array WRITE_SHAPE_METHODS = [
        'typefinderServerFilled' => 'serverFilled',
        'typefinderRespectMassAssignment' => 'respectMassAssignment',
        'typefinderImmutableOnUpdate' => 'immutableOnUpdate',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Move the removed Typefinder traits and contract to their attributes', [
            new CodeSample(
                <<<'CODE_SAMPLE'
                    class Post extends Model
                    {
                        use HasTypeOverrides;

                        public function typeOverrides(): array
                        {
                            return ['meta' => '{ seo: string }'];
                        }
                    }
                    CODE_SAMPLE,
                <<<'CODE_SAMPLE'
                    #[TypefinderOverrides(['meta' => '{ seo: string }'])]
                    class Post extends Model
                    {
                    }
                    CODE_SAMPLE,
            ),
        ]);
    }

    /** @return array<class-string<Node>> */
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /** @param  Class_  $node */
    public function refactor(Node $node): ?Node
    {
        $changed = $this->dropLegacyReferences($node);

        $changed = $this->migrate($node, ['typeOverrides' => null], TypefinderOverrides::class) || $changed;
        $changed = $this->migrate($node, self::WRITE_SHAPE_METHODS, TypefinderWriteShape::class) || $changed;

        return $this->migrate($node, ['typeDefinition' => null], TypefinderCast::class) || $changed ? $node : null;
    }

    private function dropLegacyReferences(Class_ $class): bool
    {
        $changed = false;

        foreach ($class->stmts as $key => $stmt) {
            if (! $stmt instanceof TraitUse) {
                continue;
            }

            $kept = array_values(array_filter($stmt->traits, fn (Node\Name $trait): bool => ! $this->isNames($trait, [self::OVERRIDES_TRAIT, self::WRITE_SHAPE_TRAIT])));

            if (count($kept) === count($stmt->traits)) {
                continue;
            }

            $changed = true;

            if ($kept === []) {
                unset($class->stmts[$key]);
            } else {
                $stmt->traits = $kept;
            }
        }

        $class->stmts = array_values($class->stmts);
        $implements = array_values(array_filter($class->implements, fn (Node\Name $interface): bool => ! $this->isName($interface, self::TYPE_DEFINITION)));

        if (count($implements) !== count($class->implements)) {
            $class->implements = $implements;
            $changed = true;
        }

        return $changed;
    }

    /**
     * Replaces the legacy methods with one attribute: every legacy method the
     * class has must return a constant expression, and the class must not
     * have the attribute yet.
     *
     * @param  array<string, ?string>  $methods  legacy method => named argument (null: the first, positional)
     * @param  class-string  $attribute
     */
    private function migrate(Class_ $class, array $methods, string $attribute): bool
    {
        $args = [];
        $found = [];

        foreach ($methods as $name => $argument) {
            $method = $class->getMethod($name);

            if (! $method instanceof ClassMethod) {
                continue;
            }

            $value = $this->returnedConstant($method);

            if (! $value instanceof Expr) {
                return false;
            }

            $found[] = $method;
            $args[] = new Arg($value, name: $argument === null ? null : new Identifier($argument));
        }

        if ($found === [] || $this->hasAttribute($class, $attribute)) {
            return false;
        }

        $class->stmts = array_values(array_filter($class->stmts, static fn (Node $stmt): bool => ! in_array($stmt, $found, true)));
        $class->attrGroups[] = new AttributeGroup([new Attribute(new FullyQualified($attribute), $args)]);

        return true;
    }

    /** What the method returns, when its body is `return <constant expression>;`. */
    private function returnedConstant(ClassMethod $method): ?Expr
    {
        $stmts = $method->stmts ?? [];

        if (count($stmts) !== 1 || ! $stmts[0] instanceof Return_ || ! $stmts[0]->expr instanceof Expr) {
            return null;
        }

        return $this->isConstant($stmts[0]->expr) ? $stmts[0]->expr : null;
    }

    private function isConstant(Expr $expr): bool
    {
        if ($expr instanceof String_ || $expr instanceof Int_ || $expr instanceof Float_ || $expr instanceof ConstFetch) {
            return true;
        }

        if ($expr instanceof ClassConstFetch) {
            return $expr->class instanceof Node\Name && $expr->name instanceof Identifier;
        }

        if ($expr instanceof Concat) {
            return $this->isConstant($expr->left) && $this->isConstant($expr->right);
        }

        if ($expr instanceof Array_) {
            return array_all($expr->items, fn (ArrayItem $item): bool => ! $item->unpack && ! $item->byRef
                && (! $item->key instanceof Expr || $this->isConstant($item->key))
                && $this->isConstant($item->value));
        }

        return false;
    }

    /** @param  class-string  $attribute */
    private function hasAttribute(Class_ $class, string $attribute): bool
    {
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $existing) {
                if ($this->isName($existing->name, $attribute)) {
                    return true;
                }
            }
        }

        return false;
    }
}
