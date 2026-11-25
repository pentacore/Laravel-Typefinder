<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\Collections;

use Illuminate\Http\Resources\Attributes\Collects;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Laravel 13's `#[Collects]`. Read by name, so the attribute class need not
 * exist (Laravel 12).
 */
#[Collects(WidgetResource::class)]
class AttributeCollection extends ResourceCollection {}
