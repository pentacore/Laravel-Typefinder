<?php

declare(strict_types=1);

/**
 * Global namespace on purpose: PSR-4 cannot load it, tests `require_once` it.
 */
enum TypefinderGlobalStatus: string
{
    case Active = 'active';
}
