<?php

declare(strict_types=1);

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Global namespace on purpose: PSR-4 cannot load it, tests `require_once` it.
 */
class TypefinderGlobalItemResource extends JsonResource {}
