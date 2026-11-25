<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\Dangling;

use App\Models\LegacyModel;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderResource;

/** References a `#[TypefinderIgnore]`d model (#138). */
#[TypefinderResource(shape: ['legacy' => LegacyModel::class])]
class LegacyShapeResource extends JsonResource {}
