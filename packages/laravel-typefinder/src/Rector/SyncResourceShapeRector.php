<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Rector;

use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Analysis\ReturnedArray;
use Pentacore\Typefinder\Analysis\ReturnedArrayReader;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Attributes\TypefinderResource;
use Pentacore\Typefinder\Rector\Support\ShapeDocSync;
use Pentacore\Typefinder\Rector\Support\ValueTypeGuesser;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Type\ObjectType;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Brings a resource's PHPDoc `array{…}` on `toArray()` in line with the
 * literal keys `toArray()` returns, so the resource drift check has nothing
 * to report: a missing key is added (`mixed` unless its type is clear), a
 * required key that is never returned is removed, and a required key that
 * is conditional or not always returned is made optional. A resource with
 * `#[TypefinderResource]` (whose attribute shape wins) or
 * `#[TypefinderIgnore]` is skipped, and so is a doc without a shape.
 */
final class SyncResourceShapeRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition("Sync the PHPDoc array shape of a JSON resource's toArray() with the keys it returns", [
            new CodeSample(
                <<<'CODE_SAMPLE'
                    /** @return array{id: int, legacy: string} */
                    public function toArray(Request $request): array
                    {
                        return ['id' => $this->id, 'name' => 'x', 'email' => $this->when($this->admin, 'y')];
                    }
                    CODE_SAMPLE,
                <<<'CODE_SAMPLE'
                    /** @return array{id: int, name: string, email?: string} */
                    public function toArray(Request $request): array
                    {
                        return ['id' => $this->id, 'name' => 'x', 'email' => $this->when($this->admin, 'y')];
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
        if ($node->isAnonymous() || ! $this->isObjectType($node, new ObjectType(JsonResource::class))) {
            return null;
        }

        if ($this->hasAttribute($node->attrGroups, [TypefinderResource::class, TypefinderIgnore::class])) {
            return null;
        }

        $method = $node->getMethod('toArray');
        $doc = $method?->getDocComment();

        if (! $method instanceof ClassMethod || ! $doc instanceof Doc) {
            return null;
        }

        $synced = new ShapeDocSync(new ValueTypeGuesser($this->getType(...)))->sync($doc->getText(), $this->returns($method));

        if ($synced === null) {
            return null;
        }

        $method->setDocComment(new Doc($synced, $doc->getStartLine(), $doc->getStartFilePos(), $doc->getStartTokenPos()));
        $method->setAttribute(AttributeKey::PHP_DOC_INFO, null);

        return $node;
    }

    /**
     * The returns of `toArray()`, as the resource drift check reads them: a
     * bare `return [];` beside returns with keys is a guard, not a shape.
     *
     * @return list<ReturnedArray>
     */
    private function returns(ClassMethod $method): array
    {
        $returns = new ReturnedArrayReader()->returns($method);
        $withKeys = array_values(array_filter($returns, static fn (ReturnedArray $returned): bool => $returned->parts !== []));

        return $withKeys === [] ? $returns : $withKeys;
    }

    /**
     * @param  array<AttributeGroup>  $groups
     * @param  list<class-string>  $classes
     */
    private function hasAttribute(array $groups, array $classes): bool
    {
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->isNames($attribute->name, $classes)) {
                    return true;
                }
            }
        }

        return false;
    }
}
