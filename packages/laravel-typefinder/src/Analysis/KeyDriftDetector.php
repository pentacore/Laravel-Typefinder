<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Analysis;

use Pentacore\Typefinder\Resolvers\PhpDocShapeResolver;
use Pentacore\Typefinder\Support\GeneratedClasses;

/**
 * Compares the literal keys code produces with the keys a declaration
 * promises, key names only: a resource's `toArray()` against its
 * `#[TypefinderResource] shape`, PHPDoc `@return` shape or model type
 * ({@see ResourceDrift}), and the props an action passes to
 * `Inertia::render()` against its `#[TypefinderPage]` ({@see PageDrift}).
 *
 * Precision over recall: a key is reported missing only when every return is
 * closed (fully literal), an undeclared key only when it is a literal key, and
 * a key as conditional only when it is conditional everywhere it appears.
 * Unreadable or unparsable source reports nothing.
 */
final readonly class KeyDriftDetector
{
    /** Inertia prop wrappers that leave the prop out of the first load. */
    public const array INERTIA_WRAPPERS = [
        'Inertia\\Inertia::optional',
        'Inertia\\Inertia::lazy',
        'Inertia\\Inertia::defer',
    ];

    /** Props Inertia's own middleware shares with every page. */
    private const array INERTIA_SHARED = ['errors'];

    private ResourceDrift $resourceDrift;

    private PageDrift $pageDrift;

    /**
     * @param  ?PhpDocShapeResolver  $phpDocShapes  Reads the PHPDoc shape of a
     *                                              resource that also has an attribute shape; build it without
     *                                              `onWarn` so its warnings are not repeated.
     * @param  ?ReturnedArrayReader  $reader  Defaults to one that treats
     *                                        `Inertia::optional|lazy|defer()` as conditional.
     * @param  list<string>  $sharedPageProps  Props the app shares with every
     *                                         page ({@see InertiaSharedProps}); with `errors`, never reported
     *                                         as not passed.
     */
    public function __construct(?PhpDocShapeResolver $phpDocShapes = null, ?ReturnedArrayReader $reader = null, array $sharedPageProps = [])
    {
        $reader ??= new ReturnedArrayReader(self::INERTIA_WRAPPERS);
        $this->resourceDrift = new ResourceDrift($phpDocShapes ?? new PhpDocShapeResolver(new GeneratedClasses), $reader);
        $this->pageDrift = new PageDrift($reader, array_values(array_unique([...self::INERTIA_SHARED, ...array_map(strval(...), $sharedPageProps)])));
    }

    /**
     * Drift between a resource extraction's declared keys and the literal keys
     * its own `toArray()` returns. A model-typed resource (attribute `model:`
     * or the name convention) is compared with its model's keys, as one
     * aggregated warning, when `$model` (that model's extraction) is given.
     *
     * @param  array{name: string, fqcn: class-string, shape: array<string, mixed>}  $extraction
     * @param  ?array{name: string, columns: list<array{name: string}>, relationships: list<array{name: string}>}  $model
     * @return list<string>
     */
    public function checkResource(array $extraction, ?array $model = null): array
    {
        return $this->resourceDrift->check($extraction, $model);
    }

    /**
     * {@see self::checkPage()} for each action and component, after merging
     * the `#[TypefinderPage]` entries one action declares for one component
     * (the attribute is repeatable) key by key, as the generated type does:
     * every declared prop counts, and a prop is optional when any entry
     * lacks it or lists it as optional. Types are not compared.
     *
     * @param  list<array{component: string, props: array<array-key, string>, optional: list<string>, source: string}>  $pages
     * @return list<string>
     */
    public function checkPages(array $pages): array
    {
        return $this->pageDrift->checkPages($pages);
    }

    /**
     * Drift between a `#[TypefinderPage]` and the props its own action
     * passes to `Inertia::render('X', …)` / `inertia('X', …)` for the same
     * component (each matching call, with any chained `->with()`, is one
     * set of props). An action that renders elsewhere (a helper, a base
     * controller) has no matching call and reports nothing. Shared props
     * (`errors`, the app's shared props) are never reported as not passed,
     * and an action that calls `Inertia::share()` itself may pass any prop.
     *
     * @param  array{component: string, props: array<array-key, string>, optional: list<string>, source: string}  $page
     * @return list<string>
     */
    public function checkPage(array $page): array
    {
        return $this->pageDrift->checkPage($page);
    }
}
