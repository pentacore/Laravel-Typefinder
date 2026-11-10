<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;

/**
 * Global namespace on purpose: PSR-4 cannot load it, tests `require_once` it.
 */
class TypefinderGlobalModel extends Model {}
