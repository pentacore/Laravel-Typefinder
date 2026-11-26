<?php

declare(strict_types=1);

use Pentacore\Typefinder\Rector\AddTypefinderPageRector;
use Pentacore\Typefinder\Rector\SyncSharedPropsShapeRector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rules([
        AddTypefinderPageRector::class,
        SyncSharedPropsShapeRector::class,
    ]);
};
