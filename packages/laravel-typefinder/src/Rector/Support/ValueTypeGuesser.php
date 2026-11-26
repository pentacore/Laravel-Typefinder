<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Rector\Support;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Pentacore\Typefinder\Analysis\ReturnedArrayReader;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure as ClosureExpr;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Guesses the {@see ValueType} of a prop or resource value from the type
 * PHPStan infers for it in a Rector run. Precision over recall: anything it
 * is not sure about is unknown.
 *
 * Closures are what they return (Inertia and resources call them), and so
 * are the Inertia prop wrappers (`Inertia::optional(fn () => …)`). A
 * `JsonResource` is unknown: Inertia sends it wrapped in `data`, which a
 * page prop cannot yet declare (#241).
 */
final readonly class ValueTypeGuesser
{
    private const string INERTIA = 'Inertia\\Inertia';

    /** Inertia's prop wrappers, whose first argument is the value (lower case). */
    private const array WRAPPERS = ['optional', 'lazy', 'defer', 'always', 'merge', 'deepmerge', 'once'];

    /** @param  Closure(Expr): Type  $typeOf  PHPStan's type of an expression in its scope */
    public function __construct(private Closure $typeOf) {}

    public function guess(Expr $value): ValueType
    {
        $value = $this->unwrap($value);

        if (! $value instanceof Expr || $this->isResource($value)) {
            return ValueType::unknown();
        }

        return $this->fromType(($this->typeOf)($value));
    }

    public function fromType(Type $type): ValueType
    {
        if ($type->isNull()->yes()) {
            return ValueType::scalar('null');
        }

        $inner = TypeCombinator::removeNull($type);
        $guess = $this->nonNull($inner);

        return $type->isNull()->maybe() ? $guess->orNull() : $guess;
    }

    private function nonNull(Type $type): ValueType
    {
        if ($type->isString()->yes()) {
            return ValueType::scalar('string');
        }

        if ($type->isBoolean()->yes()) {
            return ValueType::scalar('boolean');
        }

        if (TypeCombinator::union(new IntegerType, new FloatType)->isSuperTypeOf($type)->yes()) {
            return ValueType::scalar('number');
        }

        $class = $this->generatedClass($type);

        if ($class !== null) {
            return ValueType::ofClass($class);
        }

        if ($type->isList()->yes()) {
            return $this->element($type->getIterableValueType());
        }

        $classes = $type->getObjectClassNames();

        if (count($classes) === 1 && is_a($classes[0], Collection::class, true)) {
            return $this->element($type->getTemplateType(Collection::class, 'TValue'));
        }

        return ValueType::unknown();
    }

    private function element(Type $type): ValueType
    {
        $element = $this->nonNull($type);

        return $element->list || $element->nullable ? ValueType::unknown() : $element->asList();
    }

    /** @return ?class-string an Eloquent model or backed enum, the classes Typefinder emits a type for */
    private function generatedClass(Type $type): ?string
    {
        $classes = $type->getObjectClassNames();

        if (count($classes) !== 1) {
            return null;
        }

        $class = $classes[0];

        return class_exists($class) && (is_subclass_of($class, Model::class) || is_subclass_of($class, BackedEnum::class))
            ? $class
            : null;
    }

    /** The value a closure or Inertia prop wrapper stands for, or null when that is not one expression. */
    private function unwrap(Expr $value): ?Expr
    {
        for ($depth = 0; $depth < 4; $depth++) {
            if ($value instanceof ArrowFunction) {
                $value = $value->expr;

                continue;
            }

            if ($value instanceof ClosureExpr) {
                $stmts = $value->stmts;

                if (count($stmts) !== 1 || ! $stmts[0] instanceof Return_ || ! $stmts[0]->expr instanceof Expr) {
                    return null;
                }

                $value = $stmts[0]->expr;

                continue;
            }

            if ($value instanceof StaticCall
                && $value->class instanceof Name
                && $value->name instanceof Identifier
                && in_array($value->name->toLowerString(), self::WRAPPERS, true)
                && ReturnedArrayReader::refersTo($value->class, self::INERTIA)) {
                $first = $value->args[0] ?? null;

                if (! $first instanceof Arg) {
                    return null;
                }

                $value = $first->value;

                continue;
            }

            return $value;
        }

        return null;
    }

    private function isResource(Expr $value): bool
    {
        $class = match (true) {
            $value instanceof StaticCall, $value instanceof New_ => $value->class,
            default => null,
        };

        return $class instanceof Name && is_a($class->toString(), JsonResource::class, true);
    }
}
