<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Rector;

use Pentacore\Typefinder\Analysis\KeyDriftDetector;
use Pentacore\Typefinder\Analysis\PageDrift;
use Pentacore\Typefinder\Analysis\ReturnedArray;
use Pentacore\Typefinder\Analysis\ReturnedArrayReader;
use Pentacore\Typefinder\Analysis\ReturnedPart;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Attributes\TypefinderPage;
use Pentacore\Typefinder\Rector\Support\ReturnedExprs;
use Pentacore\Typefinder\Rector\Support\ValueType;
use Pentacore\Typefinder\Rector\Support\ValueTypeGuesser;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Tags a controller action that returns `Inertia::render('X', [...])` (or
 * `inertia('X', [...])`) with `#[TypefinderPage]`, one per component. Props
 * come from the literal keys, as the page drift check reads them; a prop is
 * optional when it is wrapped (`Inertia::optional()`, `defer()`, `lazy()`)
 * or missing from some render of the component. Types are guessed where
 * PHPStan is sure (scalars, models, enums, lists of them) and `unknown`
 * otherwise, for you to fill in.
 *
 * Skipped: actions that already have a `#[TypefinderPage]`, classes and
 * actions with `#[TypefinderIgnore]`, and a component any render of which
 * passes props that are not a literal array (`$props`, a spread, a dynamic
 * key), as its keys cannot be read.
 */
final class AddTypefinderPageRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Add #[TypefinderPage] to controller actions that render an Inertia page', [
            new CodeSample(
                <<<'CODE_SAMPLE'
                    public function show(Post $post): Response
                    {
                        return Inertia::render('Posts/Show', ['post' => $post, 'title' => 'Post']);
                    }
                    CODE_SAMPLE,
                <<<'CODE_SAMPLE'
                    #[TypefinderPage(component: 'Posts/Show', props: ['post' => Post::class, 'title' => 'string'])]
                    public function show(Post $post): Response
                    {
                        return Inertia::render('Posts/Show', ['post' => $post, 'title' => 'Post']);
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
        if ($node->isAnonymous() || $this->hasAttribute($node->attrGroups, TypefinderIgnore::class)) {
            return null;
        }

        $changed = false;

        foreach ($node->getMethods() as $method) {
            if (! $method->isPublic() || $method->isStatic() || $method->isAbstract() || $method->isMagic()) {
                continue;
            }

            if ($this->hasAttribute($method->attrGroups, TypefinderPage::class) || $this->hasAttribute($method->attrGroups, TypefinderIgnore::class)) {
                continue;
            }

            foreach ($this->pages($method) as $component => $renders) {
                $attribute = $this->attribute($component, $renders);

                if ($attribute instanceof Attribute) {
                    $method->attrGroups[] = new AttributeGroup([$attribute]);
                    $changed = true;
                }
            }
        }

        return $changed ? $node : null;
    }

    /**
     * The props of each returned render, by component, in source order.
     *
     * @return array<string, list<ReturnedArray>>
     */
    private function pages(ClassMethod $method): array
    {
        $drift = new PageDrift(new ReturnedArrayReader(KeyDriftDetector::INERTIA_WRAPPERS), []);
        $pages = [];

        foreach (ReturnedExprs::of($method) as $expr) {
            $render = $drift->render($expr);

            if ($render !== null) {
                $pages[$render['component']][] = $render['props'];
            }
        }

        return $pages;
    }

    /** @param  non-empty-list<ReturnedArray>  $renders */
    private function attribute(string $component, array $renders): ?Attribute
    {
        if (array_any($renders, static fn (ReturnedArray $props): bool => $props->isOpen())) {
            return null;
        }

        $guesser = new ValueTypeGuesser($this->getType(...));
        /** @var array<string, ?ValueType> $types */
        $types = [];
        /** @var array<string, int> $seen key => number of renders that pass it */
        $seen = [];
        /** @var array<string, true> $optional */
        $optional = [];

        foreach ($renders as $props) {
            foreach ($props->parts as $part) {
                if ($part->kind !== ReturnedPart::ITEM || $part->key === null) {
                    continue;
                }

                $key = $part->key;
                $type = $part->value instanceof Expr ? $guesser->guess($part->value) : ValueType::unknown();
                $types[$key] = array_key_exists($key, $types) && ! ($types[$key]?->equals($type) ?? false) ? ValueType::unknown() : $type;
                $seen[$key] = ($seen[$key] ?? 0) + 1;

                if ($part->conditional) {
                    $optional[$key] = true;
                }
            }
        }

        foreach ($seen as $key => $count) {
            if ($count < count($renders)) {
                $optional[$key] = true;
            }
        }

        $args = [new Arg(new String_($component), name: new Identifier('component'))];

        if ($types !== []) {
            $items = [];

            foreach ($types as $key => $type) {
                $items[] = new ArrayItem($this->typeExpr($type ?? ValueType::unknown()), new String_($key));
            }

            $props = new Array_($items, ['kind' => Array_::KIND_SHORT]);

            if (count($items) > 2) {
                $props->setAttribute(AttributeKey::NEWLINED_ARRAY_PRINT, true);
            }

            $args[] = new Arg($props, name: new Identifier('props'));
        }

        if ($optional !== []) {
            $args[] = new Arg(
                new Array_(array_map(static fn (string $key): ArrayItem => new ArrayItem(new String_($key)), array_map(strval(...), array_keys($optional))), ['kind' => Array_::KIND_SHORT]),
                name: new Identifier('optional'),
            );
        }

        return new Attribute(new FullyQualified(TypefinderPage::class), $args);
    }

    /** A TypeScript string, or a class-string where Typefinder resolves one (`User::class.' | null'`). */
    private function typeExpr(ValueType $type): Expr
    {
        $suffix = ($type->list ? '[]' : '').($type->nullable ? ' | null' : '');

        if ($type->kind === ValueType::CLASS_NAME) {
            $class = new ClassConstFetch(new FullyQualified($type->name), 'class');

            return $suffix === '' ? $class : new Concat($class, new String_($suffix));
        }

        return new String_(($type->kind === ValueType::SCALAR ? $type->name : 'unknown').$suffix);
    }

    /** @param  array<AttributeGroup>  $groups */
    private function hasAttribute(array $groups, string $class): bool
    {
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->isName($attribute->name, $class)) {
                    return true;
                }
            }
        }

        return false;
    }
}
