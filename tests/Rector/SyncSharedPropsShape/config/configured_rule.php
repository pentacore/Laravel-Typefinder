<?php

declare(strict_types=1);

use Pentacore\Typefinder\Rector\SyncSharedPropsShapeRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withImportNames(removeUnusedImports: true)
    ->withRules([SyncSharedPropsShapeRector::class]);
