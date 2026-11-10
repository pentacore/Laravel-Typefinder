<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Tests\Fixtures\Resources\PhpDoc\Concerns\DocumentedBaseResource;
use Tests\Fixtures\Resources\PhpDoc\Concerns\DocumentedToArray;

class TraitToArrayResource extends DocumentedBaseResource
{
    use DocumentedToArray;
}
