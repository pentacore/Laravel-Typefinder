<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Rector;

/**
 * Rector sets for apps that use Typefinder, for `->withSets([...])`. Rector
 * itself is not a dependency of Typefinder; install `rector/rector` to use
 * them.
 */
final class TypefinderSetList
{
    /**
     * Rules for every app: {@see QualifyTypefinderReturnRector},
     * {@see SyncResourceShapeRector} and {@see MigrateLegacyTypefinderApiRector}.
     */
    public const string TYPEFINDER = __DIR__.'/config/typefinder.php';

    /**
     * Rules for apps with Typefinder's Inertia pages turned on:
     * {@see AddTypefinderPageRector} and {@see SyncSharedPropsShapeRector}.
     */
    public const string INERTIA = __DIR__.'/config/inertia.php';
}
