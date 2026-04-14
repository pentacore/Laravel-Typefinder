<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Contracts;

interface HasTypeDefinition
{
    public static function typeDefinition(): string;
}
