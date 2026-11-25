<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\Collections;

use Illuminate\Http\Resources\Json\ResourceCollection;

class GadgetCollection extends ResourceCollection
{
    public $collects = WidgetResource::class;
}
