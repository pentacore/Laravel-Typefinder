<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc;

use Illuminate\Http\Resources\Json\JsonResource;
use Tests\Fixtures\Resources\PhpDoc\Concerns\AliasedOwnerToArray;

class TraitAliasToArrayResource extends JsonResource
{
    use AliasedOwnerToArray;
}
