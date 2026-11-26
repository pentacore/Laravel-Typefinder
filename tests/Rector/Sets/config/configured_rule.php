<?php

declare(strict_types=1);

use Pentacore\Typefinder\Rector\TypefinderSetList;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withImportNames(removeUnusedImports: true)
    ->withSets([TypefinderSetList::TYPEFINDER, TypefinderSetList::INERTIA]);
