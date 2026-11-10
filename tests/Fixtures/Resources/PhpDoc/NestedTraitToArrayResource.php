<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Fixtures\Resources\PhpDoc\Concerns\NestedDocumentedToArray;

class NestedTraitToArrayResource extends JsonResource
{
    use NestedDocumentedToArray;
}
