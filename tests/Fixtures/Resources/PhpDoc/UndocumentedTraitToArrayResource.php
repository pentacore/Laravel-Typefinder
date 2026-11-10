<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Tests\Fixtures\Resources\PhpDoc\Concerns\DocumentedBaseResource;
use Tests\Fixtures\Resources\PhpDoc\Concerns\UndocumentedToArray;

class UndocumentedTraitToArrayResource extends DocumentedBaseResource
{
    use UndocumentedToArray;
}
