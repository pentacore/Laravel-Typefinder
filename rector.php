<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/packages/laravel-typefinder/src',
        __DIR__.'/tests',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        carbon: true,
        rectorPreset: true,
    );
