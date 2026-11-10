<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Concerns;

use App\Models\User;

trait ComposesOwner
{
    /**
     * @return array{owner: User}
     */
    protected function ownerPart(): array
    {
        return ['owner' => $this->user];
    }
}
