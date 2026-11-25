<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\Collections;

use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderResource;

#[TypefinderResource(shape: ['id' => 'number'])]
class WidgetResource extends JsonResource {}
