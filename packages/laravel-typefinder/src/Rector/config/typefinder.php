<?php

declare(strict_types=1);

use Pentacore\Typefinder\Rector\MigrateLegacyTypefinderApiRector;
use Pentacore\Typefinder\Rector\QualifyTypefinderReturnRector;
use Pentacore\Typefinder\Rector\SyncResourceShapeRector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rules([
        QualifyTypefinderReturnRector::class,
        SyncResourceShapeRector::class,
        MigrateLegacyTypefinderApiRector::class,
    ]);
};
